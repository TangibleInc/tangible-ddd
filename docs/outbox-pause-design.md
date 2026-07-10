# Outbox Pause — Design Note

**Status:** BUILT on `v3-ddd` (2026-07-05), all four suites green. Companion to
`docs/integration-event-evolution.md` (0.2.0) — the pause is the *operational-lifecycle*
half of the same outbox/dispatch machinery that doc re-types.

Shipped (fold-in variant): `IOutboxRepository::set_pause/clear_pause/is_paused` +
`fetch_pending` exclusion (ddd-src/Infra/Persistence/OutboxRepository.php); `OutboxProcessor`
+ `ProcessingResult` moved to `Infra\Services`; datastream `Pause/ResumeDeliveryCommand` +
handlers → declared hold `{holder:'delivery_panic', selector:EventReadyForDelivery, until:-1}`;
`EmergencyStopService` re-backed by the pause hold; `DeliveryService` stop-gap removed;
`emergency_stop` option retired; REST `DeliveryController` (pause/resume/status).
Tests: framework `OutboxPauseTest`, datastream `DeliveryPauseTest` + `RestDeliveryTest`.
**Remaining:** the admin UI button (front-end) that calls the REST endpoints — not built
(untested-JS out of scope); endpoints are ready.

## 0. Purpose (the WHY — keep this in view)

Let a consumer (**tangible-datastream** is the driving case) **pause its outbox event
processing** — a **panic button in the admin UI**. While paused, events keep being
**captured** and simply **accumulate in the outbox** (durable, `pending`); nothing is
delivered, nothing is lost. Un-press → the backlog drains normally.

This supersedes the stop-gap currently in datastream (`EmergencyStopAwareOutboxRepository`
+ `DeliveryService` returning `Retryable`), which was built during the v3 audit and is the
wrong shape — see §9.

## 1. Core principle

**Pause is a relay lifecycle state, never a message/domain outcome.** You do not stamp
rows `paused`, you do not encode pause as a delivery verdict (that was the original
data-loss bug: stop → `Fatal` → row marked `completed` → dropped). You stop the *pump*;
durable records sit untouched and flow again on resume.

**Feed-only, drain-down (v1).** Pausing gates the **feed** (outbox → Action Scheduler),
not execution. The **outbox becomes the pause buffer**: with the feed gated, pending rows
accumulate durably; whatever was already handed to AS finishes (a small, bounded tail —
graceful drain-down). No churn, no DLQ pollution, no burned attempts, single holding pen.

## 2. Layer placement

`OutboxProcessor` is a **transactional-outbox relay** — transport/persistence mechanics
(locks, worker ids, backoff, DLQ), zero domain, orchestrates only infra ports. It is
**infrastructure mislabeled as Application** (`Application\Outbox`). Its adapters
(`ActionSchedulerOutboxPublisher`, `RoutingOutboxPublisher`, `OutboxIntegrationEventBus`)
already live in `Infra\Services`. **Companion refactor: move `OutboxProcessor`
(+ `ProcessingResult`) → `Infra\Services`.** Pause is therefore an infra concern living in
infra — consistent, not a special case.

## 3. The pause model

### Two hold types, by control model

- **Declared holds** — stored, explicit place/release. For stops with no ambient signal
  (deploy, "pause while we investigate", a maintenance window). A record:

  ```
  { holder: string, selector: '<EventType>' | '*', until: -1 | <unix_ts> }
  ```
  - `selector`: exact integration-event type, or `'*'` wildcard (all). No globs (YAGNI).
  - `until`: `-1` = indefinite (held till released); future ts = timed auto-expiry. One
    field unifies held + timed.
  - `holder`: the multi-holder key — several coexist; a type is paused if ANY active
    record matches; release is per-holder (a `deploy` release must not clear an `ops` hold).

- **Derived holds** — live predicates, not stored. For stops whose signal already exists
  (datastream `emergency_stop` setting; schema migration = `db_version != CONST`;
  maintenance-mode flag). Evaluated at fetch time via a per-context filter. No place/release.

`is_paused(type) = (any active declared record matching type) OR (any derived predicate true)`.

### Never stamp rows

Stamping is the pause-on-message anti-pattern: N writes to pause, misses future arrivals,
adds a bogus row status competing with pending/completed/failed/dlq. Rows are never touched.

### Terminology discipline

Coordinators **suspend** (LongProcess owns its own suspension). The relay **pauses**. Rows
carry neither. Don't bleed the words.

## 4. Enforcement — one chokepoint

Pause state lives on **`IOutboxRepository`** (it already owns outbox persistence — rows,
locks, DLQ, `get_stats`), and is enforced in **`fetch_pending()`**, the single row-selection
point:

```
IOutboxRepository:
  set_pause(holder, selector, until): void
  clear_pause(holder): void
  // fetch_pending() applies pauses internally:
  //   - any active wildcard declared hold OR any derived predicate → return []
  //   - else → SELECT ... WHERE status='pending' AND event_type NOT IN (:paused_types) ...
```

Derived predicates fold into the same fetch via a filter:
`... OR apply_filters($config->hook('outbox_paused'), false)`. An infra repo consulting a WP
hook is fine (it's already knee-deep in `$wpdb`). Result: **`OutboxProcessor` stays entirely
pause-unaware** — it fetches and processes whatever it's handed.

**Not `OutboxConfig`.** Pauses are runtime-mutable operational state; `OutboxConfig` is a
static `from_options()` snapshot of tunables. Keep pause as repo methods, not a config field.

**SRP guard:** keep the repo's pause surface minimal (`set_pause`/`clear_pause`/apply). Don't
let it grow into general "outbox admin."

## 5. Scoping — free, via IDDDConfig

`IDDDConfig` is the framework's per-plugin namespacing (`prefix/table/option/hook/as_group/
domain_action/integration_action`). The outbox repo is already constructed with it, so pause
state namespaces automatically and isolation is **structural** (each plugin's DI builds its
own `OutboxRepository(its IDDDConfig)`):

| piece | via | datastream | cred |
|---|---|---|---|
| declared holds (option) | `$config->option('outbox_pauses')` | `tangible_datastream_outbox_pauses` | `tgbl_cred_outbox_pauses` |
| derived filter | `$config->hook('outbox_paused')` | `tangible_datastream_outbox_paused` | `tgbl_cred_outbox_paused` |

A datastream pause cannot touch cred's relay — same reason their outbox tables don't collide.
Matches the framework law: "storage is scoped by each consumer's IDDDConfig."

**Global "pause all plugins" is NOT the default** — it would be a deliberate loop over
registered contexts, matching "normal ops = one context; only explicit fan-out crosses."

## 6. Coordinators are excluded (deliberately)

`LongProcess` / `BehaviourWorkflow` are **not** pause targets. They own first-class
suspension (`AwaitEvent`/await-mechanisms, `status=suspended`, cursor states). And they're
handled **correctly by consequence**: a saga awaiting an integration event won't resume while
dispatch is paused (the event never fires) → it just keeps waiting → resume → it wakes. No
separate lever; no infra-pause overlaid on a domain state machine.

**One exception (0.2.0 `AwaitAll` timeouts, wall-clock by decision):** the await-timeout
alarm is a direct AS action scheduled at suspend time — NOT an outbox row — so pause does
not freeze it. A pause that outlives a suspended saga's remaining timeout budget fires the
timeout against events sitting undeliverable in the frozen outbox (`TIMEOUT_PROCEED` judges
a partial set; `TIMEOUT_FAIL` compensates). Deliberately NOT engineered around (pause-aware
snooze rejected — derived holds have no interval history; see 0.2.0 spec §6.3 + §10). Ops
rule: before a long pause, check suspended sagas' deadlines.

## 7. Hardness (v1 vs later)

- **v1: feed-only (drain-down).** Gate `fetch_pending`. In-flight AS tail finishes.
- **Deferred: hard freeze** (also stop execution — the already-scheduled AS handler actions).
  Requires the dispatch callback to **defer-by-reschedule** (can't no-op: the outbox already
  completed the row on handoff, so a no-op = drop). Costs churn + two holding places. Build
  only if a genuine "nothing crosses *this instant*, incl. queued" guarantee is needed
  (breach/egress-freeze). Co-design with the 0.2.0 `integration_listener()` ceremony, since
  both wrap the same AS callback.

## 8. Exposure — the panic button (DECIDED)

**Split:** the **framework** exposes pause *primitives* (`IOutboxRepository::set_pause/
clear_pause`, plus the per-type derived filter for other consumers). The **consumer** owns
the *UI* and the *reason*. The framework never knows about a "panic button."

**The button is delivery-specific, not a global freeze.** It targets exactly one integration
event type — `EventReadyForDelivery` — so it means "stop sending to destinations," not
"freeze all integration processing." Today datastream's outbox is delivery-only so the two
coincide, but scoping to the type keeps the meaning precise and safe if datastream ever emits
another integration event. (This rules out a plain boolean setting/filter, which can only
express wildcard.)

**Mechanism: a proper audited command (DECIDED).**

```
UI / REST  →  PauseDeliveryCommand   →  handler → IOutboxRepository::set_pause(
                                                     holder:  'delivery_panic',
                                                     selector: EventReadyForDelivery::name(),
                                                     until:   -1)
           →  ResumeDeliveryCommand  →  handler → IOutboxRepository::clear_pause('delivery_panic')
```

- **Per-type + audited in one move.** The declared-hold record carries the `selector`
  (`EventReadyForDelivery`), and dispatching through the command bus lands a `command_audit`
  row — so the trace shows *who* paused delivery and *when*, alongside the events it held.
- **Non-heretical layering:** UI → command → handler → repo. The handler calling a repository
  is allowed (framework law); it dispatches no further command (no nested dispatch).
- **Enforcement:** `fetch_pending` sees the `delivery_panic` hold and excludes
  `EventReadyForDelivery` rows — deliveries accumulate as `pending`; any other integration
  event type keeps flowing. `OutboxProcessor` stays pause-unaware.
- Holder key `'delivery_panic'` is distinct from other declared holders (deploy/migration), so
  a resume clears only the panic, never someone else's hold.

Interim note: until the framework `fetch_pending` pause lands, the button has no relay to
gate. Either ship the button *with* the framework work, or have `PauseDeliveryHandler` flip
the existing `emergency_stop` setting in the meantime (which the §9 stop-gap already reads) —
the UI stays stable (always dispatches the command); only the handler body migrates from
"flip setting" to "set_pause declared hold" when the relay-pause exists.

## 9. Migration from the current stop-gap

`EmergencyStopAwareOutboxRepository` (the consumer subclass of a framework repo) has been
**reverted (2026-07-04)** — deleted, DI restored to the framework `OutboxRepository`, its
test removed. The wrong-shape infra is gone.

What remains is only the one-line `DeliveryService::deliver()` verdict: `Fatal → Retryable`
on emergency stop. That is not infra — it is the minimal correct stop-gap that prevents the
data loss (re-queue, never drop) until the framework relay-pause below is built. When the
framework `fetch_pending` pause lands, datastream switches to the derived-pause filter
(§8 option a) and this one-liner becomes redundant (removable then).

## 10. Relationship to the 0.2.0 evolution

- **Same dispatch layer** the 0.2.0 doc re-types; pause is its operational-lifecycle half.
- 0.2.0's **durable, resurrectable record** model makes "outbox as pause buffer" native (a
  paused event is just a record not yet dispatched — sibling to await/replay).
- 0.2.0 **deprecates `AsyncWordPressActionHandler`** → removes a pausable surface; post-0.2.0
  surfaces = outbox feed + `IntegrationListener` dispatch + process-resume (excluded).
- **Adjacent gap** (not this note, worth naming): the outbox **completes the row on handoff**,
  so retries are dead across the AS hop (the hole datastream's inline `DeliveryOutboxPublisher`
  routes around). Pause / replay / that retry gap are all lifecycle concerns on the same hop;
  0.2.0's total-round-trip codec is what would make a unified "integration-record lifecycle"
  (pending → dispatched → consumed, with pause/defer/replay/retry) coherent.

## 11. v1 scope

Build (framework): declared holds (`set_pause`/`clear_pause`) with per-type `selector` +
derived filter, enforced in `fetch_pending`, per-context via IDDDConfig; move
`OutboxProcessor` → `Infra\Services`.
Build (datastream): `PauseDeliveryCommand` / `ResumeDeliveryCommand` → declared hold on
`selector: EventReadyForDelivery`, `holder: 'delivery_panic'`; wire the admin panic button to
dispatch them.
Defer: hard-freeze (execution-side), per-lane beyond exact+wildcard, throttled resume,
global cross-context pause.
