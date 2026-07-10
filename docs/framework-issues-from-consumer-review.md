# Tangible DDD Framework Issues From Consumer Review

Date: 2026-06-24

This note captures framework-level issues found while reviewing real consumers,
mainly `tangible-datastream` v3 and `tangible-cred`. It is deliberately not a
datastream product critique. The point is to identify DDD framework contracts
that consumers already rely on, or are likely to rely on.

## Mental model corrections

Each DDD-consuming plugin owns its own DDD table set. The framework is shared
code, but storage is scoped by each consumer's `IDDDConfig`.

So:

- `tangible-datastream` has its own command audit, outbox, DLQ, process, and
  workflow tables.
- `tangible-cred` has its own equivalent table set.
- `correlation_id` is not shared storage.
- `correlation_id` is a propagated trace token that may appear in multiple
  plugin table sets if an integration/outbox/process boundary carries it across.

For an inspector dashboard, normal list views should be scoped to one context.
Only explicit trace mode should fan out across registered contexts by
`correlation_id`.

## Critical: outbox `message_kind` schema mismatch

`install_outbox_tables()` creates `message_kind ENUM('event','command')`, but
`OutboxRepository::write()` inserts `integration_event`.

Files:

- `ddd-wordpress/tables.php`
- `ddd-src/Infra/Persistence/OutboxRepository.php`

Why this matters:

Consumers such as datastream publish delivery intents through
`OutboxIntegrationEventBus`. If MySQL rejects the insert, the repository still
returns a generated event id because it does not check `$wpdb->insert()`.
That can make capture -> match -> outbox appear successful while no outbox row
exists.

Required fix:

- Either change the enum to include `integration_event`, or write `event`.
- Check the return value of `$wpdb->insert()`.
- Throw a framework exception with `$wpdb->last_error` when insert fails.
- Add an integration test that installs the real table and writes a real
  integration event.

## Critical: DLQ final failure data is stale

`OutboxProcessor::process_batch()` calculates `$new_attempts = $entry->attempts + 1`.
When that reaches `max_attempts`, it calls `move_to_dlq($entry->event_id)`.
`move_to_dlq()` then reads the old row and stores the old `attempts` and old
`last_error`, not the current exception that caused the DLQ transition.

Files:

- `ddd-src/Application/Outbox/OutboxProcessor.php`
- `ddd-src/Infra/Persistence/OutboxRepository.php`

Why this matters:

The DLQ row is supposed to be the forensic record of the terminal failure. At
the moment it can undercount attempts and preserve the previous error instead
of the final one.

Required fix:

- Pass current exception message and final attempt count into `move_to_dlq()`.
- Or call a repository method that atomically records the final failure and
  moves the row.
- Test the final attempt count and final error stored in DLQ.

## High: DLQ stats query references missing `resolved_at`

`OutboxRepository::get_stats()` queries:

```sql
SELECT COUNT(*) FROM integration_dlq WHERE resolved_at IS NULL
```

The DLQ table schema does not define `resolved_at`.

Files:

- `ddd-wordpress/tables.php`
- `ddd-src/Infra/Persistence/OutboxRepository.php`

Why this matters:

Any dashboard, health check, or operational screen that calls `get_stats()` can
fail against the real schema.

Required fix:

- Add `resolved_at DATETIME NULL` and related indexes if unresolved/resolved DLQ
  state is a real concept.
- Or remove the `resolved_at` predicate and model all DLQ rows as unresolved.
- Add a schema-backed test for `get_stats()`.

## High: correlation scope is too blunt

`CorrelationContext` is static request/process state. `CorrelationMiddleware`
initializes a correlation id if none exists, then unconditionally resets all
correlation state in `finally`.

Files:

- `ddd-src/Application/Correlation/CorrelationContext.php`
- `ddd-src/Application/Correlation/CorrelationMiddleware.php`
- `ddd-wordpress/integration-events.php`

Correct model:

- New top-level command: generate random `correlation_id`.
- Outbox/integration/process boundary: restore persisted `correlation_id`.
- Command audit/outbox/process rows persist that value.
- Command completion should not accidentally destroy a boundary-level trace
  context that still wraps the current callback.

Current problem:

Two successive commands from the same integration callback are allowed but
smelly. Today, the first command can reset the restored integration correlation,
causing the second command to generate a new unrelated correlation id.

Required fix:

- Introduce scoped correlation management.
- Integration handlers should restore correlation around the whole callback.
- Command middleware should preserve an existing outer correlation and clear
  only command-local state on exit.
- A stack/scope API is enough; do not use this to support nested command
  dispatch.

Suggested shape:

```php
CorrelationContext::with($correlation_id, function () {
  // Boundary adapter work here.
});
```

## High: nested command dispatch is not prohibited

The framework currently has no runtime guard against command-in-command.

Architectural law:

Command dispatch is an application boundary. Command handlers may call domain
services, repositories, ports, outbox/event bus, and ordinary application
services. Command handlers must not dispatch commands.

Allowed:

- REST controller -> command
- WP hook -> command
- CLI/cron/admin action -> command
- Integration event callback -> command
- Boundary callback -> multiple successive commands, with a smell

Forbidden:

- Command handler -> command

Required fix:

- Add a command dispatch guard middleware, preferably outermost.
- Track command execution depth in static framework state.
- Throw `NestedCommandDispatchForbidden` if depth is already greater than zero.
- Add tests proving successive boundary commands are allowed but nested command
  dispatch fails.

## Medium: integration event helper assumes positional payloads

`TangibleDDD\WordPress\integration_action()` extracts correlation metadata and
then returns `array_values($wrapped)`. This works for positional-scalar
integration events, but it destroys associative payload shape.

Datastream had to implement its own `tds_integration_action()` helper because
`EventReadyForDelivery::from_args()` expects a named associative payload.

Files:

- `ddd-wordpress/integration-events.php`
- `tangible-datastream/includes/hooks/integration/index.php`

Required fix:

Add an official associative-payload helper or event reconstruction path.

Possible APIs:

```php
integration_action_assoc(EventClass::class, function (array $payload) {});
```

or:

```php
integration_event(EventClass::class, function (EventClass $event) {});
```

The second shape is cleaner if the framework can standardize event
reconstruction.

## Medium: process runner restores correlation without a scope reset

`ProcessRunner::continue_scheduled()` and `resume_on_event()` call
`CorrelationContext::init($process->correlation_id())`, then run the process.
There is no obvious scoped restoration or reset after the process run.

Files:

- `ddd-src/Application/Process/ProcessRunner.php`

Why this matters:

Action Scheduler can execute multiple callbacks in the same PHP process. A
process continuation should not leave correlation static state behind for
unrelated callbacks.

Required fix:

- Use the same scoped correlation API proposed above.
- Wrap process continuation/resume in `CorrelationContext::with(...)`.

## Medium: outbox locking depends on `FOR UPDATE SKIP LOCKED`

`OutboxRepository::fetch_pending()` uses `FOR UPDATE SKIP LOCKED`.

File:

- `ddd-src/Infra/Persistence/OutboxRepository.php`

Why this matters:

This is correct for modern MySQL/MariaDB versions that support it, but it is a
runtime compatibility boundary. If the supported WordPress host matrix includes
older database engines, outbox processing can fail.

Required fix:

- Document minimum database version.
- Or add a fallback locking strategy.
- Add a health check that confirms the configured DB supports the chosen lock
  syntax.

## Inspector implications

The generic DDD drill inspector should treat a plugin context as a storage
boundary, not as a trace boundary.

Normal tabs:

- One selected context.
- No SQL unions across plugin table sets.
- Query that context's command audit, outbox, DLQ, long process, workflow, and
  storage state.

Trace tab:

- User provides a `correlation_id`.
- Inspector fans out across registered DDD contexts.
- Each context returns normalized trace fragments from its own tables.
- The UI merges/sorts fragments in memory.

Good trace fragment shape:

```php
[
  'context' => 'tangible-datastream',
  'kind' => 'command|outbox|dlq|process|workflow|app',
  'occurred_at' => '...',
  'correlation_id' => '...',
  'local_id' => '...',
  'related_ids' => [],
  'summary' => '...',
  'payload_preview' => [],
]
```

Useful inspector smell rules:

- Same boundary callback emits multiple command audit rows: warning.
- Command dispatched while another command is active: error.
- Outbox row missing `correlation_id`: error.
- Outbox row missing `command_id`: warning unless process/system-created.
- DLQ row missing final error/final attempt: error.
- Suspended LongProcess waiting for an unregistered event type: error.
- Cross-context `correlation_id`: highlight as a useful propagated trace, not a
  bug.

## Priority order

1. Fix outbox `message_kind` mismatch and insert error handling.
2. Fix DLQ final failure persistence.
3. Fix `get_stats()` / DLQ schema mismatch.
4. Add command dispatch guard.
5. Add scoped correlation context and apply it to integration actions and
   process continuation.
6. Add official associative/event-object integration callback helper.
7. Document or harden the outbox locking compatibility boundary.
