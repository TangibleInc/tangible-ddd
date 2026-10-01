# Wave 3 change requests: pdo-adapters (round 1)

Author: pdo-default, branch `wave3/pdo-adapters`. Scope of this round: the `Defaults/Pdo` adapter set, `schema/mysql8`, `tests/Pdo` and `phpunit.pdo.xml`. `DurableRuntime::compose()` and `examples/plain-php-durable` are round 2.

No ratified interface changed. Everything below is additive (new classes, or public methods on new pdo-only classes), or a note the coordinator should rule on.

## CR-PDO-1: `IHostConnection` lands now, exactly as register 3.3

Register 8 says pdo-default ships `IHostConnection` and the `DurableRuntime` stub in wave 1. Neither existed on `extraction/ddd-packages` at `8c74686`. `TangibleDDD\Defaults\Pdo\IHostConnection` is added with the register 3.3 signature unchanged. The `DurableRuntime` stub is still absent and comes with `compose()` in round 2.

Additive on the concrete class: `PdoConnection::assertErrmode(): void` (public). `PdoTransactionBoundary` calls it at construction, so the register 3.2 rule "throws a configuration error at construction" holds even if the host switched ERRMODE off after wrapping the PDO. The configuration error is the new `PdoConfigurationError extends \LogicException`.

## CR-PDO-2: pdo-only additions on the adapters (not on any port)

| Class | Addition | Why |
|---|---|---|
| `PdoOutboxStore` | `appendFact(OutboxRecord, ?string $eventClass)`, `eventClassOf(string)`, `connection()` | the deliver job needs the fact class (same as CR sf-1 in ddd-symfony); `connection()` backs `sharesConnectionWith` |
| `PdoPauseStore` | `activePatterns(\DateTimeImmutable)`, `connection()` | the claim excludes paused rows inside its SELECT (`REGEXP_LIKE(..., 'c')`), as sf does |
| `PdoJobStore` | `deliveryOf(ClaimedWakeup): ?DeliveryJob`, `hasLiveIntent(int)`, `connection()` | see CR-PDO-3 |
| `DeliveryJob` | new value class | the fact carried by a `deliver` job |
| `MySqlNamedLock` | `static nameOf(LockKey)` | = `LockKey::mysqlName()`, for tests and diagnostics |
| `SchemaSql` | new class: `files()`, `statements(prefix)`, `dump(prefix)` | prints `schema/mysql8` with the prefix substituted for the host's own tooling; it never executes anything (the sf equivalent is `ddd:schema:dump`) |
| `SchemaCheck` | `problems(): list<string>`, `assert(): void` | the register names the class without a shape |

## CR-PDO-3: one jobs table is both the wakeup scheduler and the relay transport

The register says pdo delivers through "a `deliver` job row per fact in `{prefix}_ddd_jobs`" (3.5), and wakeups are rows in the same table (3.6). `PdoJobStore` therefore implements both `IWakeupScheduler` and `ITransport`:

- `submit()` writes a `WakeKind::Deliver` row with idempotency key `deliver:{event_id}` and the wrapped envelope. It is due at the absolute `$dueAt` and returns `job:{id}`. Resubmitting a fact whose job is still pending returns that job's reference and writes no second row.
- `claimDue()` returns both kinds. `ClaimedWakeup`/`WakeupIntent` carry no payload, so the round-2 `Drain` reads the fact with `deliveryOf()`.
- `sharesConnectionWith()` is true only for a `PdoOutboxStore` on the same `IHostConnection` object, so the core relay runs submit and accept in one transaction. `PdoRelayTest` checks this against the real `OutboxProcessor`.

## CR-PDO-4: `PdoOperatorView` returns arrays until core ships `IOperatorView`

`Runtime\Ops\IOperatorView`, `OperatorItem` and `Layer` (register 3.10) are core's wave-3 work and were not on the branch point. `PdoOperatorView::list(?string $layer = null, int $limit = 100)` returns arrays with exactly the register's OperatorItem fields (`layer, consumer, key, attempts, budget, last_error, first_seen, repair_actions`) plus `detail`, and takes the `Layer` enum's string values. Register 5.1 already says "for pdo `OperatorView::list()` returning arrays the host renders". **Request to core:** when `IOperatorView` lands, pdo will add `implements IOperatorView` and map to `OperatorItem` in round 2. The array form is the only shape callers have now, so it stays.

## CR-PDO-5: schema naming the coordinator should know about

- Relay pauses: the column is `held_until`, not `until`. `UNTIL` is a reserved word in MySQL; the sf table uses `until`.
- The process table's logical name is `ddd_processes`. wp's `long_processes` keeps its own name. The column layout matches wp's (JSON `business_data`, `steps`, `payload`, `await_mechanism`), plus `ignition_key`, `quarantine_reason` and `version`.
- Every table is `utf8mb4_bin`, so ids, event types and class names compare case-sensitively, as PHP and `fnmatch()` do.
- Times are `DATETIME(6)` in UTC, written from `IClock`. Nothing uses `NOW()`, so the session `time_zone` does not matter.

## CR-PDO-6 (ruling wanted): lease-expired re-claims are not counted as relay attempts on pdo

ddd-symfony counts a re-claim of an expired lease as an attempt and dead-letters the row at claim after `max_attempts` (its CR sf-8). The wave-2 notes do not list sf-8 among the ratified requests, and the core mem double does not do this. pdo follows the mem double: a row whose submitter keeps dying is re-claimed forever, and `PdoOperatorView` does not show it as failing. If the coordinator makes sf-8 a core rule, pdo adds it in round 2 (one `CASE` in the claim UPDATE plus `deadLetter` at claim).

## CR-PDO-7: `MySqlNamedLock` and core's `INamedLock`

The parallel `wave3/core` branch adds `Runtime\Lock\INamedLock` (its CR-W3C-2) for `LegacyProcessStore`'s ignition gate. That branch is not merged into this one, so `MySqlNamedLock` implements only `IProcessLock`. Once `INamedLock` is merged, pdo can add it in round 2: `acquire(string $name, float)` and `release(string $name)` over the same GET_LOCK code.

## CR-PDO-8 (other owners)

- **packaging:** wire `tests/harness/run.sh core-pdo` to `vendor/bin/phpunit -c packages/ddd-core/phpunit.pdo.xml`. The single testsuite is named `pdo` and runs every case in both prepare modes. Connection settings come from env (`DDD_PDO_HOST`, `DDD_PDO_PORT`, `DDD_PDO_USER`, `DDD_PDO_PASSWORD`, `DDD_PDO_DATABASE`), and the bootstrap drops and recreates its own database. The two-process drain script follows in round 2. `packages/ddd-core/schema/` must stay in the release artifact; `.gitattributes` already keeps it.
- **conformance:** the 37 pdo scenario ids need a pdo `HostFixture` built on these adapters. It is not written here (it belongs to `packages/ddd-conformance/**`).
