# Wave 1 core: register change requests

Status: **proposed, not ratified.** Wave 1 froze the delivery, lock and
process contracts (register section 8). The core branch (`wave1/core`)
deviates from the register 3.x sketches in the places listed below. Each item
needs a register revision before merge, or the listed fallback applies.

This file sits here only because the wave-1 core author owns
`packages/ddd-core/src/Runtime/**` and not `docs/extraction/`. The
coordinator should fold it into the register (or `docs/extraction/wave1-notes.md`)
and delete it at the wave-2 move.

Downstream authors this affects: wave-2 sf (ledger table,
`DbalPostgresOutboxStore`, Postgres session lock), wave-3 pdo and wp adapters
(ledger, `GET_LOCK` lock, process store).

## CR-1. IDeliveryLedger: terminal exhaustion marker (register 3.5, 5.1)

Register has 4 methods. Branch has 7:

```php
interface IDeliveryLedger {
  public function delivered(string $subscriberId, string $eventId): bool;
  public function markDelivered(string $subscriberId, string $eventId): void;
  public function markFailed(string $subscriberId, string $eventId, string $error, int $attempt): void;
  public function attempts(string $subscriberId, string $eventId): int;
  public function lastError(string $subscriberId, string $eventId): ?string;   // NEW: error of the latest markFailed; null for unknown pair
  public function markExhausted(string $subscriberId, string $eventId): void;  // NEW: terminal marker; idempotent; only after compensation returned
  public function exhausted(string $subscriberId, string $eventId): bool;      // NEW
}
```

Why: without the marker, "budget reached and compensated" is
indistinguishable from "budget reached, compensation threw or the process
crashed before it ran". D1's `failureCommand()` then either never fires or
fires on every later delivery. With the marker the compensation is durable
and retryable (at-least-once, effectively once after success).

Adapter obligation: ledger tables need `last_error` (text) and an
`exhausted_at` (nullable timestamp, UTC) column.

Fallback if rejected: drop the three methods; `failureCommand()` becomes
fire-once-at-best (lost on a throw or crash).

## CR-2. Subscriber::onExhausted and DeliveryOutcome's four lists (register 3.5)

```php
final class Subscriber {
  public function __construct(
    public readonly string $id, public readonly int $priority,
    public readonly string $eventClassOrMarker, public readonly \Closure $handle,
    public readonly ?\Closure $onExhausted = null,   // NEW: \Closure(IIntegrationEvent, \Throwable): void
  ) {}
}
final class DeliveryOutcome {
  public function __construct(array $delivered, array $skipped, array $failed, array $exhausted) {} // register: {delivered, failed}
  public function needsRetry(): bool;   // failed !== []
  public function isComplete(): bool;   // failed === [] && exhausted === []
}
```

Why: the invoker must not know about commands; the registrar closes over
`IExternalEffectCommand::failureCommand()` and hands the invoker a callback.
`skipped` (ledger hits) and `exhausted` (terminal) are what operators and the
delivery runner need to tell "done" from "given up".

Fallback if rejected: the invoker would have to inspect commands itself,
which couples Runtime\Delivery to the command bus.

## CR-3. SubscriptionRegistrar constructor and the IProcessEntry port (register 3.5, ruling #80)

```php
namespace TangibleDDD\Runtime\Process;
interface IProcessEntry {                                         // NEW port
  public function ignite(string $processClass, IIntegrationEvent $event, string $eventId): void; // #[StartsOn] only; insertIgnited
  public function resume(IIntegrationEvent $event): void;         // findWaitingFor, each under its lock
}
final class SubscriptionRegistrar {
  public function __construct(ISubscriptionRegistry $registry,
                              ProcessRunner|IProcessEntry|null $runner = null,   // register: ProcessRunner $runner
                              ?ContainerInterface $services = null) {}
}
```

Transitional: a plain 0.6 `ProcessRunner` (which does not implement
`IProcessEntry`) throws `\LogicException` at construction, because wrapping
it would lose the bug-2 ignition dedup that lives inside its hook closure.
Wave 2 makes `ProcessRunner implements IProcessEntry` and narrows the
parameter to `IProcessEntry`.

Fallback if rejected: the registrar cannot register processes until wave 2.

## CR-4. IProcessLock::forceReleaseAll() (register 3.7)

```php
interface IProcessLock {
  // acquire, release, heldCount as in the register, plus:
  public function forceReleaseAll(): int;   // NEW: release every lock held through THIS instance; returns count; never throws
}
```

Used by `RuntimeReset::betweenMessages()`: after it records a lock leak
(`heldCount() > 0`), it force-releases so the leak fails loudly once but is
not sticky across the next message.

Adapter obligation: the sf Postgres session-lock adapter
(`pg_advisory_unlock` per held key, or `pg_advisory_unlock_all()` when the
connection is dedicated) and the `GET_LOCK` adapter (`RELEASE_LOCK` per held
name, or `RELEASE_ALL_LOCKS()` on MySQL 8). Adapters track their handles
already for `heldCount()`.

Fallback if rejected: a leak stays held until the worker dies.

## CR-5. IProcessStore::touch() and versionOf() (register 3.8, 3.7 fencing)

```php
interface IProcessStore {
  // insertIgnited, insert, find, save, findWaitingFor, findStranded as in the register, plus:
  public function touch(int $id, int $expectedVersion): int;  // NEW: UPDATE … SET version = version + 1 WHERE id = ? AND version = ?; throws ConcurrentProcessModification on 0 rows
  public function versionOf(int $id): ?int;                   // NEW: current version; null for unknown id
}
```

Why: register 3.7 already requires "a fenced version touch before each step
dispatch", but 3.8 gives the store no method for it. These two make the
fence a port operation instead of adapter SQL inside the runner.

Fallback if rejected: the runner does `save($p, $v)` with an unchanged
process as the touch; `versionOf` is replaced by carrying the version from
`find()` (needs a `find()` return shape change instead).

## CR-6. Uuid::v5 location (register 3.1, D13)

Branch: `TangibleDDD\Runtime\Ids\NameBasedUuid::v5(string $namespace_uuid, string $name): string`.
Register: `TangibleDDD\Domain\Shared\Uuid::v5()`. Wave 1 may not edit the
existing `Uuid` class. Wave 2 (the move) adds `Uuid::v5()` delegating to
`NameBasedUuid::v5()`. Adapters should call `Uuid::v5()` from wave 2 on.

## Known pending changes (not register deviations)

- **PSR-3 logging.** Runtime classes (`IntegrationDelivery`,
  `ReentrantProcessLock`, `InMemoryTransactionBoundary`,
  `LoggingSignalDispatcher`) take `?\Closure(string): void` and fall back to
  `error_log()` through `Runtime\Support\Log`, because `psr/log` is not in the
  root tree. Packaging adds `psr/log` to the root and `ddd-core` manifests;
  wave 2 changes these constructor parameters to `?Psr\Log\LoggerInterface`,
  keeping `Log` as the null fallback. Constructor signatures change once more.
- **Correlation::current_fact(): ?FactRef (D13).** `FactRef` ships in wave 1
  (`TangibleDDD\Application\Correlation\FactRef`). The accessor needs an edit
  to the existing `Correlation` class, which wave 1 forbids; it lands with the
  wave-2 move. Register section 8's wave-2 note ("current_fact() ships in
  wave 1") should read "FactRef in wave 1, current_fact() in wave 2".
- **Id-less legacy payloads (wave-3 wp brief).** `IntegrationDelivery::deliver()`
  throws `\InvalidArgumentException` for an envelope without `__event_id`. In
  0.6 such payloads (a consumer firing the integration hook by hand, a
  hand-built payload) still reach DDD-registered callbacks, without dedup.
  The wp adapter must route id-less payloads around the invoker: call the
  subscriber callbacks directly, unledgered, in priority order, and log it.
  Otherwise existing listeners stop firing.
- **Placement outside the wave-1 globs.** `TangibleDDD\Infra\IConsumerIdentity`
  (`packages/ddd-core/src/Infra/`), `TangibleDDD\Application\Persistence\TransactionalCommandMiddleware`
  and `TangibleDDD\Application\Correlation\FactRef` use the namespaces the
  register assigns (3.1, 1.4, 3.9), inside core's register-level ownership.
  Wave 2's `IDDDConfig extends IConsumerIdentity` targets this FQCN.
  Coordinator to confirm.
