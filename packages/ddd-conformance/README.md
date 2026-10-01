# tangible/ddd-conformance

Dev-only. The shared scenarios of [contract-register.md section 4](../../docs/extraction/contract-register.md), written once and run on every host (mem, pdo, wp, sf).

```sh
cd packages/ddd-conformance
composer install
vendor/bin/phpunit --group mem                       # one host
vendor/bin/phpunit --group relay.lease-fencing       # one scenario id, every host present
vendor/bin/phpunit --group simulated                 # the multi-process cases on the in-process simulation (not a host)
vendor/bin/phpunit --list-groups                     # host groups + scenario ids
```

## Layout

| Path | What |
|---|---|
| `src/HostFixture.php` | What every host provides: its ports (one connection) plus pipeline operations and fault seams |
| `src/ProcessHost.php`, `src/ProcessWorker.php` | Optional seam for the wave-3 process and lock scenarios (CR-W3CP-1) |
| `src/FreshProcesses.php`, `src/WebRequests.php`, `src/RelayRace.php`, `src/StatementErrors.php`, `src/AuditSinkFaults.php`, `src/RecordsSignals.php` | The other optional seams (below) |
| `src/Scenarios/*Scenarios.php` | Abstract scenario cases. Each scenario method has `#[Group('<scenario id>')]` and is named `test_<id with . and - as _>` |
| `src/ScenarioCatalogue.php` | The 44 ids with the wave each must pass per host (a copy of the register table, pinned by `tests/CatalogueTest.php`), and `CASES`: the abstract case that declares each id |
| `src/Fixtures/`, `src/Fixtures/Process/` | Commands, facts and the conformance processes the scenarios use |
| `src/Support/` | Helpers hosts may reuse |
| `src/Mem/` | The mem host on the real ddd-core classes, and `MemSimulatedHostFixture` (the simulation, not a host) |
| `tests/Mem/*Test.php` | Concrete mem classes, `#[Group('mem')]` |
| `tests/Simulated/*Test.php` | `#[Group('simulated')]`: the `-`-on-mem cases on the simulation, the process cases under `StartMode::Deferred`, and an aborting-engine run of `cmd.*` |

## Which cases a host extends

`ScenarioCatalogue::casesFor($host, $wave)` lists the abstract cases that declare every id due on `$host` by `$wave`. For wave 3:

| Case | Ids | Needs |
|---|---|---|
| `CommandScenarios` | `cmd.*`, `audit.sink-fails` | HostFixture; `AuditSinkFaults` + `RecordsSignals` for `audit.sink-fails`; `StatementErrors` for the CR sf-7 part of `cmd.commit-failure` |
| `RelayScenarios` | the process-free `relay.*` | HostFixture; `RelayRace` for the CR sf-3 part of `relay.lease-fencing` |
| `DeliveryScenarios` | the process-free `delivery.*` | HostFixture |
| `WorkerScenarios` | `worker.no-leak` | HostFixture |
| `ProcessDeliveryScenarios` | the four `.process` variants | ProcessHost |
| `ProcessScenarios` | `process.ignition-race`, `process.manual-start-in-drain`, `process.timeout-vs-event`, `process.await-before-dispatch`, `process.intent-survives-queue-failure`, `process.stale-wakeup` | ProcessHost |
| `LockScenarios` | `lock.contention`, `lock.acquire-error`, `lock.reentrant-balance` | ProcessHost |
| `ConcurrencyScenarios` | `process.await-all-concurrent`, `lock.namespace` | ProcessHost with a real second connection (worker 2) |
| `FreshProcessScenarios` | `relay.fresh-process-pickup`, `relay.crash-after-commit`, `process.crash-mid-step`, `process.fresh-process-resume` | ProcessHost + FreshProcesses |
| `WebStartScenarios` | `process.start-from-web` (sf only) | ProcessHost + WebRequests |

A case also contains ids that are `-` on some host (e.g. `lock.namespace` on wp): that host overrides the method (same `#[Group]`) with `skipForChangeRequest()` naming the register cell.

## Adding a host

1. Implement `HostFixture`. `setUp(ScenarioContext)` must create a fresh schema for the one test (`$context->uniqueName('pdo')` gives a safe database or schema name), apply the host schema and bind every port to that one connection. Do not wrap the test in a transaction. `tearDown()` drops the schema and clears `RuntimeReset`, `HostDefaults` and `Correlation`.
2. For each scenario case, add a concrete class with the host group:

```php
#[Group('sf')]
final class SfRelayScenariosTest extends RelayScenarios {
  protected function createFixture(): HostFixture { return new SfHostFixture(/* DSN */); }
}
```

3. Add the host's class directory to `CatalogueTest` so the ids due on that host are enforced.
4. If the host cannot express a scenario yet, override that one method (same `#[Group]`) and call `skipForChangeRequest('<request id>', '<why>')`. A missing optional seam skips on its own with its request id (`CR-CC-1`, `CR-W3CP-1`, `CR-W3CP-4`, `CR-W3CP-5`); the parts of a scenario guarded by `RelayRace` / `StatementErrors` are simply not run.

Host hooks: `RelayScenarios::whileLeased(Claim)` runs while a lease is live. wp overrides it to check that a 0.6 `fetch_pending()` skips the claimed row.

## Optional seams

Each is a separate interface, so `HostFixture` itself never changes and fixtures written against it keep compiling (CR-CC-1, CR-W3CP-1..5).

### `AuditSinkFaults`, `RecordsSignals` (wave 2)

`audit.sink-fails` needs `failNextAuditClose(string)` and `signals()` (the `IInfrastructureEvent`s emitted since setUp).

### `ProcessHost` and `ProcessWorker` (wave 3)

The process machinery, bound to the fixture's per-test schema.

| Member | Contract |
|---|---|
| `wireProcesses($starts, $awaits)` | `register_start()` / `register_event()` on every worker, including workers built later |
| `worker(1)` | The fixture's connection. Its runner uses `HostFixture::processLock()`, `boundary()`, `clock()`, `subscriptions()`, `processStore()` and `wakeups()`; its `deliverFact()` is `HostFixture::deliver()` |
| `worker(n > 1)` | Another connection / lock session over the same database (a second php-fpm child, Messenger consumer or cron run). It has only the wired ignition and resume subscribers, not the stub listeners a scenario added to `subscriptions()`. Deliveries share `HostFixture::ledger()` |
| `ProcessWorker::drainOnce()` | One `Drain::runOnce()` with that worker's runner as the wake handler and stranded scanner (W3C-R6), plus the host's delivery stage if it has one |
| `processRow()`, `processIds()`, `pendingWakeups()` | Read-back without the lock: status, step index, version, `ignition_key`, `ignited_by_event_id`; live intents in scheduling order |
| `operatorView()` | The host's merged `IOperatorView` (register 3.10) |
| `processConsumer()`, `processLockKey($id)` | The prefix runners lock and key intents under; the `LockKey` of worker 1's runner |
| `holdProcessLockElsewhere()` / `releaseProcessLockElsewhere()` | A second session holds the process lock (pdo/wp: `GET_LOCK` on another connection; sf: `pg_try_advisory_lock`) |
| `failNextProcessLockAcquire($reason)` | The next backend acquire answers NULL / false / an error, once |
| `processLockAcquisitions()` | Successful backend acquisitions (re-entrant ones are not counted) |
| `beforeNextProcessLockAcquire($fn)` | The interleaving point: `$fn` runs once right before worker 1's next backend acquire, with no transaction open on worker 1's connection. Scenarios make worker 2 act there (ignition race, timeout vs fact, concurrent AwaitAll). Put it under the core `ReentrantProcessLock` (`Support\InterleavingProcessLock` does this) |
| `failNextWakeHandoff($reason)` | The next hand-off of an intent to the host's wake transport fails, wherever the host hands over: at schedule time (wp, Action Scheduler projection), at relay time (sf, `ddd_wakeups`), or when a drain executes a claimed intent (pdo jobs, mem; `Support\WakeHandoffFaults`). The intent row must survive |

The start mode is the host's own: in-band on mem, pdo and wp; deferred on sf (CR-W3C-1). Scenarios start processes through `ProcessScenarioCase::start()`, which drains once when the first step was deferred. The process, lock and `.process` cases run green under both modes (`tests/Simulated/DeferredStart*`).

Step commands (`Fixtures\Process\StepCommand`) need no bus. They record themselves with the deterministic command id they were sent under (`Fixtures\Process\ProcessJournal`). Once the host calls `ProcessJournal::bind($scenarioRows, $boundary)` in `setUp()`, each one also commits a scenario row `cmd:{label}:{command id}`, idempotently. That is how a scenario sees a step effect made in another process.

### `FreshProcesses` (wave 3; pdo, wp, sf)

Each method runs one fresh php process against the per-test schema: a separate `php` script, WP-CLI or loopback request, or `bin/console` run. The fresh process boots like production, then calls `Support\FreshProcessBoot::boot($runner, $subscriptions, $scenarioRows, $boundary)`, which adds the conformance effect subscriber, the process wiring and the journal binding. The clock follows `advanceClock()` (`EnvOffsetClock`, `DDD_CLOCK_OFFSET`). "Killed" means a hard exit at the stated point: no `finally`, no shutdown function. `FreshRun` carries what the parent can see: whether the process died, the process id, relayed event ids, delivered count, and errors.

### `WebRequests` (wave 3; sf)

`inWebRequest($fn)` runs `$fn` as a web request on the pooled connection with the host's default start mode. `bootInBandStartOnPooledDsn()` returns what refused the boot.

### `RelayRace` (CR sf-3), `StatementErrors` (CR sf-7)

`raceNextRelayAfterSubmit($competitor)` runs `$competitor` inside the next relay step, between the transport's submission and `accept()`, as another connection. Whatever it commits stays committed. `runFailingStatement()` executes one statement the database rejects inside the open transaction and throws the driver error. On Postgres this aborts the transaction.

## The mem pipeline

Since wave 2 round 3 the mem host runs on the real ddd-core classes. The wave-1 stand-ins (CONF-1..3) are deleted, and `tests/bootstrap.php` loads only Composer autoload. `tests/Mem/MemHostCompositionTest.php` pins this.

| Piece | Core class (port form) |
|---|---|
| act bracket + audit | `CorrelationMiddleware(config, uow, Redactor, IAuditSink, IActorProvider, IAuditPolicy, IEnvironmentProvider)` |
| transaction | `TransactionalCommandMiddleware(InMemoryTransactionBoundary)` |
| facts to the outbox | `OutboxIntegrationEventBus(null, config, IFactObserver, IClock, IOutboxStore, OutboxConfig)` |
| relay step | `OutboxProcessor(config, null, OutboxConfig, null, null, logger, clock, store, transport, boundary)::process_batch()`; the crash and race seams are `between_submit_and_accept()` |
| delivery, worker reset | `IntegrationDelivery`, `RuntimeReset` |
| processes (wave 3) | `ProcessRunner(config, null, ReentrantProcessLock, InMemoryProcessStore, InMemoryWakeupScheduler, registry, boundary, clock, StartMode, logger)`; `Drain(relay, wakeups, runner, null, runner, clock, logger)::runOnce()`; `PortOperatorView` over the outbox, the process store, the ledger and the intents |

Mem workers: worker n > 1 is a second runner, re-entrant lock and registry over the same stores and the same raw `InMemoryProcessLock`, which then sees worker 1's keys as held.

`MemSimulatedHostFixture` (group `simulated`) is not a host. It simulates `FreshProcesses` and `WebRequests` in-process so the multi-process cases run here before pdo, wp and sf run them for real. A fresh process is a new object graph over the shared stores with its own journal statics. A kill restores the snapshot taken at the kill point.

Helpers other hosts may reuse (`src/Support/`):
- `ConformanceConfig`: a host-neutral `IDDDConfig` for the constructors that still type one (CR-SP-7).
- `RecordingLogger`: PSR-3.
- `RecordingOutboxStore`: turns a real relay step into a `RelayReport` by event id.
- `InterleavingProcessLock`: the `beforeNextProcessLockAcquire` point.
- `WakeHandoffFaults`: wraps a drain's wake handler.
- `FreshProcessBoot`: what a fresh process registers.
