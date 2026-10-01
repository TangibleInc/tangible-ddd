# tangible/ddd-conformance

Dev-only. The shared scenarios of [contract-register.md section 4](../../docs/extraction/contract-register.md), written once and run on every host (mem, pdo, wp, sf).

```sh
cd packages/ddd-conformance
composer install
vendor/bin/phpunit --group mem                       # one host
vendor/bin/phpunit --group relay.lease-fencing       # one scenario id, every host present
vendor/bin/phpunit --group simulated                 # the multi-process and sf-only cases on the in-process simulation (not a host)
vendor/bin/phpunit --group catalogue                 # the catalogue pins (register sections 4 and 8)
vendor/bin/phpunit --list-groups                     # host groups + scenario ids
```

## Layout

| Path | What |
|---|---|
| `src/HostFixture.php` | What every host provides: its ports (one connection) plus pipeline operations and fault seams |
| `src/ProcessHost.php`, `src/ProcessWorker.php` | Optional seam for the wave-3 process and lock scenarios (CR-W3CP-1) |
| `src/FreshProcesses.php`, `src/WebRequests.php`, `src/RelayRace.php`, `src/StatementErrors.php`, `src/AuditSinkFaults.php`, `src/RecordsSignals.php` | The other wave-2/3 optional seams (below) |
| `src/EffectHost.php`, `src/WorkflowHost.php`, `src/ProcessDecodeFaults.php`, `src/PostCommitWakeups.php` | The wave-4 optional seams (CR-W4C4-2..5, below) |
| `src/Scenarios/*Scenarios.php` | Abstract scenario cases. Each scenario method has `#[Group('<scenario id>')]` and is named `test_<id with . and - as _>` |
| `src/ScenarioCatalogue.php` | The 47 ids with the wave each must pass per host (a copy of the register table's 44 ids plus the three D3 ids of CR-W4C4-1, pinned by `tests/CatalogueTest.php`), and `CASES`: the abstract case that declares each id |
| `src/Fixtures/`, `src/Fixtures/{Process,Effects,Workflow,Codec}/` | Commands, facts, the conformance processes, the D1 effect command, the D10 workflow and the D6 facts the scenarios use |
| `src/Support/` | Helpers hosts may reuse |
| `src/Mem/` | The mem host on the real ddd-core classes, and `MemSimulatedHostFixture` (the simulation, not a host) |
| `tests/Mem/*Test.php` | Concrete mem classes, `#[Group('mem')]` |
| `tests/Simulated/*Test.php` | `#[Group('simulated')]`: the `-`-on-mem cases on the simulation (including `wakeup.post-commit`), the process, alarm and D3 cases under `StartMode::Deferred`, and an aborting-engine run of `cmd.*` |

## Which cases a host extends

`ScenarioCatalogue::cases_for($host, $wave)` lists the abstract cases that declare every id due on `$host` by `$wave`. For wave 3:

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

Wave 4 adds cases of its own, so a host class written for wave 3 runs unchanged (pinned by `CatalogueTest`). `relay.lease-fencing` (in `RelayScenarios`) also gained the CR-PDO-6 assertion in wave 4; see below.

| Case | Ids | Needs | Hosts (wave 4) |
|---|---|---|---|
| `AlarmScenarios` | `process.alarm-long` | ProcessHost | mem, pdo, wp, sf |
| `AwaitScenarios` | `process.await-keyed-precheck`, `process.await-any-cancellation`, `process.await-all-dynamic` (CR-W4C4-1, D3) | ProcessHost | mem, pdo, sf (wp `-`) |
| `CodecScenarios` | `codec.large-payload` | HostFixture | mem, pdo, wp, sf |
| `DecodeScenarios` | `decode.unknown-class` | ProcessHost + ProcessDecodeFaults | mem, pdo, wp, sf |
| `EffectScenarios` | `effect.journal-reuse` (D1) | EffectHost | mem, pdo, sf |
| `WorkflowScenarios` | `workflow.fact-ignition-once` (D10) | WorkflowHost | mem, sf |
| `PostCommitWakeupScenarios` | `wakeup.post-commit` (D14) | PostCommitWakeups | sf |

A case also contains ids that are `-` on some host (e.g. `lock.namespace` on wp): that host overrides the method (same `#[Group]`) with `skip_for()` naming the register cell.

## Adding a host

1. Implement `HostFixture`. `set_up(ScenarioContext)` must create a fresh schema for the one test (`$context->unique_name('pdo')` gives a safe database or schema name), apply the host schema and bind every port to that one connection. Do not wrap the test in a transaction. `tear_down()` drops the schema and clears `RuntimeReset`, `HostDefaults` and `Correlation`.
2. For each scenario case, add a concrete class with the host group:

```php
#[Group('sf')]
final class SfRelayScenariosTest extends RelayScenarios {
  protected function create_fixture(): HostFixture { return new SfHostFixture(/* DSN */); }
}
```

3. Add the host's class directory to `CatalogueTest` so the ids due on that host are enforced.
   Hosts outside this package (pdo in `packages/ddd-core/tests/Pdo/Conformance`, sf, wp) pin their ids in their own catalogue test instead.
4. If the host cannot express a scenario yet, override that one method (same `#[Group]`) and call `skip_for('<request id>', '<why>')`. A missing optional seam skips on its own with its request id (`CR-CC-1`, `CR-W3CP-1`, `CR-W3CP-4`, `CR-W3CP-5`, and in wave 4 `CR-W4C4-2` EffectHost, `CR-W4C4-3` ProcessDecodeFaults, `CR-W4C4-4` WorkflowHost, `CR-W4C4-5` PostCommitWakeups); the parts of a scenario guarded by `RelayRace` / `StatementErrors` / `RecordsSignals` / `ProcessHost` are simply not run.

Host hooks: `RelayScenarios::while_leased(Claim)` runs while a lease is live. wp overrides it to check that a 0.6 `fetch_pending()` skips the claimed row.

## Optional seams

Each is a separate interface, so `HostFixture` itself never changes and fixtures written against it keep compiling (CR-CC-1, CR-W3CP-1..5).

### `AuditSinkFaults`, `RecordsSignals` (wave 2)

`audit.sink-fails` needs `fail_next_audit_close(string)` and `signals()` (the `IInfrastructureEvent`s emitted since `set_up()`).

### `ProcessHost` and `ProcessWorker` (wave 3)

The process machinery, bound to the fixture's per-test schema.

| Member | Contract |
|---|---|
| `wire_processes($starts, $awaits)` | `register_start()` / `register_event()` on every worker, including workers built later |
| `worker(1)` | The fixture's connection. Its runner uses `HostFixture::lock()`, `boundary()`, `clock()`, `subscriptions()`, `process_store()` and `wakeups()`; its `deliver()` is `HostFixture::deliver()` |
| `worker(n > 1)` | Another connection / lock session over the same database (a second php-fpm child, Messenger consumer or cron run). It has only the wired ignition and resume subscribers, not the stub listeners a scenario added to `subscriptions()`. Deliveries share `HostFixture::ledger()` |
| `ProcessWorker::drain_once()` | One `Drain::run_once()` with that worker's runner as the wake handler and stranded scanner (W3C-R6), plus the host's delivery stage if it has one |
| `process_row()`, `process_ids()`, `live_intents()` | Read-back without the lock: status, step index, version, `ignition_key`, `ignited_by_event_id`; live intents in scheduling order |
| `operator_view()` | The host's merged `IOperatorView` (register 3.10) |
| `consumer_prefix()`, `lock_key($id)` | The prefix runners lock and key intents under; the `LockKey` of worker 1's runner |
| `hold_lock_elsewhere()` / `release_lock_elsewhere()` | A second session holds the process lock (pdo/wp: `GET_LOCK` on another connection; sf: `pg_try_advisory_lock`) |
| `fail_next_lock($reason)` | The next backend acquire answers NULL / false / an error, once |
| `lock_acquisitions()` | Successful backend acquisitions (re-entrant ones are not counted) |
| `before_next_lock($fn)` | The interleaving point: `$fn` runs once right before worker 1's next backend acquire, with no transaction open on worker 1's connection. Scenarios make worker 2 act there (ignition race, timeout vs fact, concurrent AwaitAll). Put it under the core `ReentrantProcessLock` (`Support\InterleavingProcessLock` does this) |
| `fail_next_handoff($reason)` | The next hand-off of an intent to the host's wake transport fails, wherever the host hands over: at schedule time (wp, Action Scheduler projection), at relay time (sf, `ddd_wakeups`), or when a drain executes a claimed intent (pdo jobs, mem; `Support\WakeHandoffFaults`). The intent row must survive |

The start mode is the host's own: in-band on mem, pdo and wp; deferred on sf (CR-W3C-1). Scenarios start processes through `ProcessScenarioCase::start()`, which drains once when the first step was deferred. The process, lock and `.process` cases run green under both modes (`tests/Simulated/DeferredStart*`).

Step commands (`Fixtures\Process\StepCommand`) need no bus. They record themselves with the deterministic command id they were sent under (`Fixtures\Process\ProcessJournal`). Once the host calls `ProcessJournal::bind($scenarioRows, $boundary)` in `set_up()`, each one also commits a scenario row `cmd:{label}:{command id}`, idempotently. That is how a scenario sees a step effect made in another process.

### `FreshProcesses` (wave 3; pdo, wp, sf)

Each method runs one fresh php process against the per-test schema: a separate `php` script, WP-CLI or loopback request, or `bin/console` run. The fresh process boots like production, then calls `Support\FreshProcessBoot::boot($runner, $subscriptions, $scenarioRows, $boundary)`, which adds the conformance effect subscriber, the process wiring and the journal binding. The clock follows `advance_clock()` (`EnvOffsetClock`, `DDD_CLOCK_OFFSET`). "Killed" means a hard exit at the stated point: no `finally`, no shutdown function. `FreshRun` carries what the parent can see: whether the process died, the process id, relayed event ids, delivered count, and errors.

### `WebRequests` (wave 3; sf)

`in_web_request($fn)` runs `$fn` as a web request on the pooled connection with the host's default start mode. `boot_inband_pooled()` returns what refused the boot.

### `RelayRace` (CR sf-3), `StatementErrors` (CR sf-7)

`race_next_relay($competitor)` runs `$competitor` inside the next relay step, between the transport's submission and `accept()`, as another connection. Whatever it commits stays committed. `fail_statement()` executes one statement the database rejects inside the open transaction and throws the driver error. On Postgres this aborts the transaction.

### `EffectHost` (wave 4, CR-W4C4-2; mem, pdo, sf)

`effect.journal-reuse` (D1). `effect_journal()` is the host's `IEffectJournal` on the host connection, so an `invalidate()` inside a command's transaction rolls back with it. `effect_bus($handlers)` is the host bus with core `EffectMiddleware` between the act bracket and Transaction, over that journal and `HostFixture::boundary()`; `RecordEffect` reaches `RecordEffect::apply()` (the host's SelfExecuting stage or a handler entry the host adds). The scenario registers its translator with the core `SubscriptionRegistrar` on `HostFixture::subscriptions()` and delivers through `HostFixture::deliver()`, so the budget is the host's delivery ledger and the failure command is fired by the core invoker. Fixtures: `Fixtures\Effects\*`; their `send()` goes through `EffectLedger::$bus`.

### `WorkflowHost` (wave 4, CR-W4C4-4; mem, sf)

`workflow.fact-ignition-once` (D10). `ignition_ledger()`, `workflows()` (the host's `IBehaviourWorkflowRepository`) and `igniter()` (the core `WorkflowIgniter` over that ledger, the host boundary and clock), all on the per-test schema. The scenario registers `Fixtures\Workflow\CronExportWorkflow` (`#[StartsOn(CronEntryDue)]`, `(workflow, minute)` key) with `igniter()->register()` on `HostFixture::subscriptions()`.

### `ProcessDecodeFaults` (wave 4, CR-W4C4-3; mem, pdo, wp, sf)

`decode.unknown-class`. `forget_class($id, $missingClass)` rewrites the stored class of a process row (and the class inside its serialized state where the host keeps one) to a class that does not exist. `stored_status($id)` and `quarantine_reason($id)` read the row's columns without decoding it.

### `PostCommitWakeups` (wave 4, CR-W4C4-5; sf)

`wakeup.post-commit` (D14). The host's relay worker (sf `ddd:relay`: LISTEN on a direct connection, wait for a notification or the poll interval) driven in steps on its own connection: `start_relay()` (first empty pass, then listening; called before the scenario commits), `relay_until($eventId, $timeout)` (wall seconds to the hand-off, or null), `await_wakeup($timeout)`, `drop_next_wakeup()` (a lost NOTIFY, one-shot), `poll_seconds()` (keep it a few seconds, longer than 1 s) and `stop_relay()`. `MemSimulatedHostFixture` simulates it (no wall time) so the scenario logic runs here first.

### `relay.lease-fencing` and CR-PDO-6 (wave 4)

After the fencing checks, the scenario lets a submitter die after every claim of one fact: each re-claim of an expired lease must come back with `attempts` = n, and the re-claim that reaches `max_attempts` must be dead-lettered inside `claim()` (the next `relay_once()` neither claims nor transports it), with attempts equal to the budget and `IReportsClaimDeadLetters::LEASE_EXPIRED_ERROR` in the error. With `ProcessHost` the operator view's relay layer must list it; with `RecordsSignals` one `OutboxDeadLettered` must be emitted. A store meets this by implementing `IReportsClaimDeadLetters` (CR-W4CE-9). `Support\RecordingOutboxStore` forwards that interface to the store it wraps.

### Other wave-4 helpers

- `ProcessJournal::mark_row($id)` / `has_row($id)` commit and read a scenario row on the host connection (the D3 precheck reads "state another context published" this way); `ProcessJournal::widgets($label)` returns the widget ids, which for the D3 processes are the minted refs, a step command was sent with.
- `ConformanceTestCase::wrap()` defaults the correlation id to `uuid5(CORRELATION_NAMESPACE, 'corr-' . event id)` (W3-WPC3-2), so a hand-built envelope fits wp's `CHAR(36)` column as a relayed one does.

## The mem pipeline

Since wave 2 round 3 the mem host runs on the real ddd-core classes. The wave-1 stand-ins (CONF-1..3) are deleted, and `tests/bootstrap.php` loads only Composer autoload. `tests/Mem/MemHostCompositionTest.php` pins this.

| Piece | Core class (port form) |
|---|---|
| act bracket + audit | `CorrelationMiddleware(config, uow, Redactor, IAuditSink, IActorProvider, IAuditPolicy, IEnvironmentProvider)` |
| transaction | `TransactionalCommandMiddleware(InMemoryTransactionBoundary)` |
| facts to the outbox | `OutboxIntegrationEventBus(null, config, IFactObserver, IClock, IOutboxStore, OutboxConfig)` |
| relay step | `OutboxProcessor(config, null, OutboxConfig, null, null, logger, clock, store, transport, boundary)::process_batch()`; the crash and race seams are `between_submit_and_accept()` |
| delivery, worker reset | `IntegrationDelivery`, `RuntimeReset` |
| processes (wave 3) | `ProcessRunner(config, null, ReentrantProcessLock, InMemoryProcessStore, InMemoryWakeupScheduler, registry, boundary, clock, StartMode, logger)`; `Drain(relay, wakeups, runner, null, runner, clock, logger)::run_once()`; `PortOperatorView` over the outbox, the process store, the ledger and the intents |
| effects (wave 4) | `EffectMiddleware(InMemoryEffectJournal, boundary)` between the act bracket and Transaction, on `effect_bus()` only; the journal is enlisted in the boundary |
| workflows (wave 4) | `WorkflowIgniter(ledger, boundary, logger, clock)` over `Mem\InMemoryWorkflowIgnitionLedger` and `Mem\InMemoryWorkflowRepository` (both enlisted; the ledger stands in for the core double W4P-R2 asks to promote to `Testing`) |

Mem workers: worker n > 1 is a second runner, re-entrant lock and registry over the same stores and the same raw `InMemoryProcessLock`, which then sees worker 1's keys as held.

`MemSimulatedHostFixture` (group `simulated`) is not a host. It simulates `FreshProcesses`, `WebRequests` and (wave 4) `PostCommitWakeups` in-process so the multi-process cases run here before pdo, wp and sf run them for real. A fresh process is a new object graph over the shared stores with its own journal statics. A kill restores the snapshot taken at the kill point.

Helpers other hosts may reuse (`src/Support/`):
- `ConformanceConfig`: a host-neutral `IDDDConfig` for the constructors that still type one (CR-SP-7).
- `RecordingLogger`: PSR-3.
- `RecordingOutboxStore`: turns a real relay step into a `RelayReport` by event id; forwards `IReportsClaimDeadLetters` (wave 4).
- `InterleavingProcessLock`: the `before_next_lock` point.
- `WakeHandoffFaults`: wraps a drain's wake handler.
- `FreshProcessBoot`: what a fresh process registers.
