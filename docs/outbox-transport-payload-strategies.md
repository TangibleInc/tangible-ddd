# Outbox transport payload strategies

The Outbox pattern decouples committing an event (transactional, in our DB) from
delivering it (best-effort, via a message broker). The transport layer publishes
outbox rows into something async — currently WordPress ActionScheduler (AS), but
the contract (`IOutboxPublisher`) is broker-agnostic and could be swapped for
RabbitMQ, SQS, NATS, Redis Streams, etc.

This doc compares two payload strategies and notes which transports support
which. It is **not** a proposal to change anything today — it documents an
optimisation that may be worth picking up if AS args storage or admin-UI
inspection ever becomes a concern.

## Strategy A — Inline payload (current default)

Publisher serializes the full event payload into the transport message.
The worker reads everything from the message; no DB round-trip needed.

```
Outbox row     │ AS args                       │ Worker
───────────────┼───────────────────────────────┼─────────────────────────
event_id       │ [{                            │ deserialize args
event_type     │   ...event props...,          │ restore correlation
payload (full) │   __correlation_id: ...,      │ reconstruct event
correlation_id │   __sequence: ...,            │ run handler
status         │   __event_id: ...,            │
               │ }]                            │
```

**Pros:**

- Worker is self-contained — no JOIN to outbox needed.
- Works for **all** transports (in-process, AS, broker-based).

**Cons:**

- Payload duplicated (outbox row + AS args row) during the in-flight window.
- Large payloads inflate both rows.

This is what `ActionSchedulerOutboxPublisher::publish()` does today.

## Strategy B — Thin args (AS-only, opt-in, not yet implemented)

Publisher ships only `event_id` (+ `correlation_id`) in transport args. The
worker fetches the full payload from the outbox row by `event_id`.

```
Outbox row     │ AS args                       │ Worker
───────────────┼───────────────────────────────┼─────────────────────────
event_id       │ [{                            │ SELECT outbox WHERE event_id=X
event_type     │   __event_id: ...,            │ hydrate event from row.payload
payload (full) │   __correlation_id: ...,      │ restore correlation
correlation_id │ }]                            │ run handler
status:                                        │ mark outbox completed
  pending → published                          │
  (NOT completed until worker confirms)        │
```

**Pros:**

- AS `args` column stays small regardless of payload size.
- The outbox row remains the canonical source of truth — convenient for an
  admin UI that wants to inspect "what's queued / in flight" without
  joining AS.
- AS dedupe via `args_hash` is irrelevant (thin args always differ via
  `event_id` UUID).

**Cons:**

- Worker does one extra `SELECT` per invocation (cheap, indexed).
- Requires the outbox row to remain readable past publish — lifecycle gains
  a `published` state distinct from `completed`, and a retention window
  prevents premature GC.
- **Only works when the worker shares the source DB** — fundamentally
  incompatible with off-process message brokers (the broker has no way to
  read your outbox table).

## Transport-by-transport compatibility

| Transport            | Inline (A) | Thin (B)                       |
|----------------------|------------|--------------------------------|
| ActionScheduler      | ✓ default  | ✓ feasible                     |
| RabbitMQ / AMQP      | ✓ required | ✗ broker process can't read outbox |
| AWS SQS / SNS        | ✓ required | ✗ same reason                  |
| Redis Streams        | ✓ required | ✗ same reason                  |
| Cloud Tasks / PubSub | ✓ required | ✗ same reason                  |
| Direct in-process    | n/a        | n/a                            |

**Take-away:** any future broker integration must ship the full payload.
Thin args is an AS-specific optimisation, not a generic capability.

## When to consider Strategy B

- AS `args` column gets large enough to bump against `max_allowed_packet`.
- Admin / forensics UI wants to inspect "what's pending" via outbox alone,
  without joining AS tables.
- AS retention purges sooner than the replay / audit window requires.

Until those bite, Strategy A is the right default. It also keeps the
publisher interface generic across transports.

## Implementation outline (if Strategy B is adopted)

The diff is concentrated:

- **`ActionSchedulerOutboxPublisher::publish()`** — emit thin args only
  (`event_id` + `correlation_id`), not the full wrapped payload.
- **`WordPress\integration_action()` wrapper** — fetch the outbox row by
  `event_id`, hydrate the event, restore correlation, run the callback,
  mark the outbox row completed.
- **`OutboxRepository::get_by_event_id()`** — must succeed regardless of
  row status (`pending`, `published`, `completed`).
- **Outbox lifecycle** — introduce a `published` state distinct from
  `completed`, with a retention window keeping rows readable past
  completion (for audit / replay).
- **Config flag** — `OutboxConfig::thin_args` (default `false`) so the
  framework supports both modes simultaneously. Existing consumers should
  see no behavioural change unless they opt in.

Ballpark: ~50 lines of framework change, no consumer-side migration.
