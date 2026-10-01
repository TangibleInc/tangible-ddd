# Wave 3 change requests: pdo-conformance (rounds 2-3)

Author: pdo-conformance, branch `wave3/pdo-conformance`. Owned paths: `packages/ddd-core/tests/Pdo/Conformance/**`, the `core-pdo` block of `tests/harness/run.sh`, and the `core-pdo` expectation in `tests/Unit/Loader/HarnessCliTest.php`. Binding inputs: register section 4, section 8 wave 3 (the 37 pdo ids), 3.3, [wave3-notes.md](wave3-notes.md), [wave3-pdo-compose-change-requests.md](wave3-pdo-compose-change-requests.md), and the seams in [packages/ddd-conformance/README.md](../../packages/ddd-conformance/README.md).

No ratified interface changed, and no file outside the owned paths changed. CR-PCF-1 and CR-PCF-2 ask other owners for additive changes; until they land, the fixture works around them inside its own directory.

## What this round did

| Item | Where |
|---|---|
| pdo `HostFixture` with `ProcessHost`, `FreshProcesses`, `RelayRace`, `StatementErrors`, `AuditSinkFaults`, `RecordsSignals` | `PdoHostFixture.php` |
| The 37 pdo ids, run twice: `ATTR_EMULATE_PREPARES` false (`Native/`) and true (`Emulated/`) | 9 scenario cases × 2 host classes |
| A fresh MySQL database per test (`ddd_w3_conf_pdo_<hash>`). Every connection the test opened is killed and the database dropped in `tearDown()` | `Support/ConformanceDatabase.php` |
| Fresh `php` processes that boot through `DurableRuntime::compose()`. A kill is `posix_kill(SIGKILL)` | `bin/fresh.php`, `Support/FreshProcessRunner.php` |
| Catalogue pin: 37 ids, both modes, no due id overridden | `PdoCatalogueTest.php` |
| Per-id gate over the JUnit log: an id passes only if it passed in both modes | `bin/check-due.php`, `Support/PdoDueGate.php`, `PdoDueGateTest.php` |
| `run.sh core-pdo`: the adapter suite, then the conformance suite and its gate, then the two-process example | `tests/harness/run.sh` |

Run it with `vendor/bin/phpunit -c packages/ddd-core/tests/Pdo/Conformance/phpunit.xml` (MySQL from `DDD_PDO_HOST`, `DDD_PDO_PORT`, `DDD_PDO_USER` and `DDD_PDO_PASSWORD`), or with `tests/harness/run.sh core-pdo`. The harness uses `DDD_MYSQL_HOST`/`DDD_MYSQL_PORT` when they are set, and otherwise the pinned `MYSQL_IMAGE`.

## How the fixture is composed (for reviewers)

- **In-process, hand-composed from compose()'s own classes in compose()'s order.** This is the same choice the sf fixture makes with the bundle. compose() takes the handlers once, creates its own `EventsUnitOfWork`, and has no seam for the audit sink, transport faults or the wake hand-off. `HostFixture` needs a bus per scenario, built from that scenario's handler map, `BusOptions` and the one `events()` unit of work. The classes are the production ones: `PdoTransactionBoundary` (Reject), `FactClassRecordingEventBus` over `OutboxIntegrationEventBus`, `OutboxProcessor` over `PdoOutboxStore` + `PdoJobStore` (submit and accept in one transaction), `ProcessRunner` on `PdoProcessStore`, `ReentrantProcessLock(MySqlNamedLock)` and `PdoJobStore`, and a core `Drain` with `PdoDeliveryWorker` and the `withClaimKinds()` wakeup view.
- **Fresh processes use `DurableRuntime::compose()` itself.** They cover `relay.fresh-process-pickup`, `relay.crash-after-commit`, `process.crash-mid-step` and `process.fresh-process-resume`. Each is a production boot: its own PDO, one compose() call with `EnvOffsetClock`, then `FreshProcessBoot::boot()`.
- **Faults sit below the adapters**, so the adapters are never replaced:
  - `ScenarioConnection` (an `IHostConnection` decorator) carries the COMMIT failure and the `GET_LOCK` seams: the NULL answer, the interleaving point, and the acquisition count.
  - `FaultInjectingTransport` wraps `PdoJobStore` for reject and no-reference. Its `sharesConnectionWith()` looks through the fixture's store decorators.
  - `WakeHandoffFaults` (conformance Support) covers the wake hand-off.
  - `FaultInjectingAuditSink` covers the audit close.
- **COMMIT failure** is simulated at the connection: the driver error is thrown before the real COMMIT, and the boundary rolls back. MySQL has no deferred constraints, so the server cannot be made to reject a COMMIT the way sf makes Postgres do.
- **Audit** goes to an in-memory sink. pdo has no audit table, and compose() wires no sink.
- **RelayRace**: the competitor runs on a third session through `RoutedOutboxStore`. `PdoOutboxStore::claim()` correctly refuses to run inside the relay's open transaction.
- **StatementErrors**: a NOT NULL violation inside the open transaction. MySQL keeps the transaction usable, so the CR sf-7 branch asserts the "committed in full" outcome.
- **Clock**: `OffsetClock` is system time plus the `advanceClock()` offset in whole seconds. A fresh process gets the same offset as `DDD_CLOCK_OFFSET`. Real time only moves forward, so a child's timestamps are never ahead of the parent's later reads. That matters for the 900 s stranded threshold in `process.crash-mid-step`.
- **Workers**: worker n > 1 is a second MySQL session with its own adapter set, runner, re-entrant lock and registry. The ledger table is shared through that session.

## CR-PCF-1 (to pdo-default, additive): `DurableRuntime::subscriptions(): ISubscriptionRegistry`

- **What.** A read accessor for the registry compose() registers listeners and processes in. That is the one the drain's `IntegrationDelivery` and the runner use.
- **Why.** A raw-PHP host that adds a subscriber after compose() cannot do it today, short of a listener class. `FreshProcessBoot::boot()` takes the registry, so `bin/fresh.php` currently reads the runner's private `subscriptions` property by closure binding. The mem fixture reads `resume_argument` the same way.
- **Compatibility.** A new public method on a pdo-only final class. When it lands, `bin/fresh.php` should switch to it (one line).
- **Related.** `PdoDeliveryWorker`'s event-type map is computed once inside compose(), so a subscriber added later only helps facts whose outbox row carries the class. Rows written through compose()'s bus always do (CR-PC-2).

## CR-PCF-2 (to pdo-default, additive): compose()'s handler array and `ITransactionalCommand`

- **Finding.** `RuntimeContainer` treats a `$handlers` key as a message only when it is an `ICommand` or `IQuery`. A command class that implements only `ITransactionalCommand` is filed as a service without any warning. Dispatching it then fails with `LogicException: No handler for …`. The conformance fixture command `CreateWidget` has exactly this shape.
- **Request.** Either treat `ITransactionalCommand` keys as messages too, or throw `\InvalidArgumentException` at compose() time when a key is a class the bus can dispatch but that is not an `ICommand`/`IQuery`.
- **Workaround here.** The fresh process publishes through its own `Support\PublishFact implements ICommand, ITransactionalCommand`, as a real application command would. The scenario semantics are unchanged: one committed command whose handler records the fact.

## CR-PCF-3 (to the coordinator, ruling wanted): which start mode the pdo gate runs

- `ProcessHost` and the conformance README say the start mode is "in-band on mem, pdo and wp". compose() defaults to `Deferred` (CR-PC-5) unless `HostDefaults` provides a `StartMode`.
- The fixture provides `StartMode::InBand` through `HostDefaults` and reads it back the way compose() does. That is how a raw-PHP host opts in, and the fresh processes do the same. `FreshProcesses::startInFreshProcess()` requires it, because `process.crash-mid-step` needs the first step to run, and die, in the fresh process, with no leased intent left behind.
- The fixture's constructor takes the mode. As a check, all 37 ids also passed on Native with `StartMode::Deferred` (fresh processes still in-band). That run is not gated. If the coordinator wants compose()'s default gated as well, the change is a third host-class set built with `new PdoHostFixture(false, StartMode::Deferred)`. It is additive and costs about 15 s.

## Notes for other owners (no action required this round)

- **packaging:** the `core-pdo` block and the `HarnessCliTest` expectation were changed here as assigned, along with the one `core-pdo` line of `usage()`. CI (`.github/workflows/**`) does not run `core-pdo` yet. It needs the host PHP with `pdo_mysql` and `posix`, which the WordPress runner image lacks (no `pdo_mysql`). Either a job with `setup-php` plus a `mysql:8.0` service, or `DDD_MYSQL_HOST` pointed at one.
- **conformance:** the pdo host lives in `packages/ddd-core/tests/Pdo/Conformance/`, outside `packages/ddd-conformance`, and pins its own ids (`PdoCatalogueTest`), as sf does. The README's "Adding a host" step 3 (add the host directory to `CatalogueTest`) does not fit hosts outside the package. Suggest it say "or pin the host's ids in the host's own catalogue test".
- **Cost:** `lock.contention` waits out `ProcessRunner::LOCK_TIMEOUT_SECONDS` (5 s) twice per prepare mode against a lock really held by another session. The conformance suite takes about 32 s on a local MySQL 8.0, and `run.sh core-pdo` under a minute after `composer install`.
