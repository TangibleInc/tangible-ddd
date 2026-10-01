# TangibleDDD\Defaults\Pdo

The plain-PHP default adapter set of ddd-core (register 3.3). **MySQL 8 tested; other drivers untested.** MariaDB is not a claimed target.

The host owns the database connection. It creates the `PDO` (with `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`), wraps it once in `PdoConnection`, and passes that same object to every adapter and to its own repositories, so one transaction covers the domain write and the outbox row. Nothing here opens a connection, parses a DSN, runs migrations, loops or sleeps.

```php
$db = new PdoConnection($pdo);                 // the host's PDO, ERRMODE_EXCEPTION
(new SchemaCheck($db, 'app_'))->assert();      // verifies; never creates
```

## DurableRuntime::compose()

The composition root for a raw-PHP host (register 3.3, ruling #80). The signature is frozen:

```php
$runtime = DurableRuntime::compose(
  IHostConnection $db, IConsumerIdentity $consumer, ContainerInterface|array $handlers,
  array $listeners, array $processes, ?IClock $clock = null,
);
$runtime->bus()->handle($command);        // command bus, core middleware order
$runtime->query_bus()->handle($query);
$report = $runtime->drain(200, 50);       // DrainReport; $runtime->drainer() is the core Drain
$runtime->operator_view()->to_arrays();   // IOperatorView, array form for rendering
```

- Tables are `{prefix}_ddd_*`, where prefix is `$consumer->prefix()`. Apply the schema with `SchemaSql::statements($prefix . '_')`.
- The command bus runs Correlation, then Effect (D1: `EffectMiddleware` over `PdoEffectJournal`; other commands pass through), then Transaction (`PdoTransactionBoundary`), then DomainEventsPublish (local `OrderedListenerDispatcher` plus the outbox bus, which records the fact class through `FactClassRecordingEventBus`), then SelfExecuting, then the handler. The query bus runs SelfExecuting, then the handler.
- The consumer is registered in `ConsumerRegistry`, so `->send()` and fact names route to it by the identity's namespace.
- `SubscriptionRegistrar` takes the listeners (at `LISTENER`) and each process's `#[StartsOn]` (at `IGNITION`) and `#[Awaits]` (at `RESUME`).
- `ProcessRunner` runs on `PdoProcessStore`, a `ReentrantProcessLock` over `MySqlNamedLock` (guarded by `RuntimeReset`), and `PdoJobStore`. The start mode is HostDefaults' `StartMode`, or `Deferred` when none is set, so a command can start a process atomically with its own writes.
- `Drain` runs four stages: relay (`OutboxProcessor`, submit and accept in one transaction), then deliveries (`PdoDeliveryWorker` through `IntegrationDelivery` and `PdoDeliveryLedger`), then due wakeups (the runner), then the stranded scan (the runner).
- `$handlers` is either a PSR-11 container or an array. In the array, a command or query class key maps to its handler, which is a callable or an object with `handle()`. Any other key is a service: a `\Closure` is a lazy factory, and an object is used as the instance. The runtime's own services resolve first: `CommandBus`, `EventsUnitOfWork`, `ProcessRunner`, `IHostConnection`, `ITransactionBoundary`, `IClock`, `IConsumerIdentity`, `IEffectJournal`, `IBehaviourWorkflowRepository`, `IWorkItemRepository` and `IWorkflowIgnitionLedger`.
- The core repair commands `ResumeStrandedProcess` and `FailStrandedProcess` (WP8-10) are handled on the bus with the runtime's ports, unless `$handlers` maps them.
- D10 (O8): `workflows()`, `work_items()` and `ignitions()` give a host's `WorkflowHandler` and a core `WorkflowIgniter` (with `boundary()`) their stores on the same connection. `journal()` is the D1 journal.

`examples/plain-php-durable/` is its acceptance fixture.

## Operator repairs

`PdoOperatorView::repair(Layer $layer, string $key, string $action, array $options = [])`, or `repair_item(OperatorItem $item, ...)`, which also refuses an action the item does not list. Each runs in one transaction with a status or lease guard (C23).

| Layer | Action | What it does | Refused when |
|---|---|---|---|
| relay | `retry` | `PdoOutboxAdministration::retry()`; a dead letter leaves the DLQ | leased; not `pending`/`dlq` without `force` |
| relay | `replay` / `discard` | replay (same `event_id`) / delete the event's newest DLQ row | no dead letter |
| delivery | `redeliver` | the fact's `deliver:{event_id}` job becomes due now | job gone or leased; subscriber exhausted or delivered |
| wakeup | `retry_wake` | the intent becomes due now (attempts kept) | gone or leased |
| process | `resume_stranded` / `fail_stranded` | the core repair handlers; options `reason` (fail, required), `compensate`, `expected_version` | lock held, not in the stranded scan, version moved |

Refusals throw `PdoRepairRefused`, or the core types `OutboxAdministrationRefused`, `OutboxRowNotFound`, `ProcessNotStranded`.

## Schema

`packages/ddd-core/schema/mysql8/*.sql` are plain `CREATE TABLE IF NOT EXISTS` statements (InnoDB, utf8mb4, DYNAMIC rows) with a `{{prefix}}` placeholder. Apply them with your own tooling; `SchemaSql::dump('app_')` prints them with the prefix substituted, and `SchemaSql::dump('app_', 6)` only the files after `006` (a host that applied the wave-3 schema runs that as its next migration). `SchemaCheck` reports a missing table, column, unique key or a non-InnoDB engine.

Schema evolution is append-only (L5): a shipped file never changes. A change is the next numbered file plus its line in `schema/mysql8/released.txt` (`SchemaSql::digest()`, statements only); `tests/Pdo/Native/SchemaReleasedTest` gates it. MySQL 8 has no `ADD COLUMN IF NOT EXISTS`, so new state goes in new tables.

| File | Table | Used by |
|---|---|---|
| 001_outbox.sql | `{prefix}ddd_outbox` | `PdoOutboxStore`, `PdoOutboxAdministration` |
| 002_dlq.sql | `{prefix}ddd_dlq` | the same |
| 003_relay_pauses.sql | `{prefix}ddd_relay_pauses` | `PdoPauseStore` |
| 004_delivery_ledger.sql | `{prefix}ddd_delivery_ledger` | `PdoDeliveryLedger` |
| 005_processes.sql | `{prefix}ddd_processes` | `PdoProcessStore` |
| 006_jobs.sql | `{prefix}ddd_jobs` | `PdoJobStore` (wakeup intents and deliver jobs) |
| 007_process_waits.sql | `{prefix}ddd_process_waits` | `PdoProcessStore` (D3 await routes) |
| 008_workflows.sql | `{prefix}ddd_behaviour_workflows`, `_meta`, `_items`, `{prefix}ddd_workflow_ignitions` | `PdoBehaviourWorkflowRepository`, `PdoWorkItemRepository`, `PdoWorkflowIgnitionLedger` (D10) |
| 009_effect_journal.sql | `{prefix}ddd_effect_journal` | `PdoEffectJournal` (D1) |
| 010_effect_recorded.sql | `{prefix}ddd_effect_recorded` | `PdoEffectJournal` entry states (E2, wave 5: `ITracksEffectState`, operator layer `effect`) |
| 011_job_facts.sql | `{prefix}ddd_job_facts` | `PdoJobStore` / `PdoParkingJobStore` (AW2, wave 5: the fact a parked resume carries) |

All times are UTC `DATETIME(6)` written from `IClock`, never `NOW()`.

## Adapters

| Class | Port | Notes |
|---|---|---|
| `PdoConnection` | `IHostConnection` | ints bound with `PARAM_INT`; `is_duplicate_key` = MySQL 1062 only |
| `PdoTransactionBoundary` | `ITransactionBoundary` | rejects nesting by default; `NestedPolicy::Savepoint` opt-in |
| `PdoOutboxStore` | `IOutboxStore`, `IReportsClaimDeadLetters` | claim = `FOR UPDATE SKIP LOCKED` + `claim_token` lease, outside any transaction; an expired-lease re-claim counts an attempt and dead-letters at `max_attempts` (CR-PDO-6) |
| `PdoOutboxAdministration` | `IOutboxAdministration`, `IOutboxRowIds` | retry removes the DLQ entry; replay keeps `event_id` |
| `PdoPauseStore` | `IRelayPauseStore` | fnmatch selectors, applied inside the claim |
| `PdoDeliveryLedger` | `IDeliveryLedger` | `last_error`, `exhausted_at` |
| `PdoProcessStore` | `IProcessStore`, `IMatchesFactAncestry` | `UNIQUE (process_class, ignition_key)`, `quarantine_reason`, version fencing; D3 routes per await, `find_waiting_for(class, key)` by key; LargeString business data (D6) |
| `PdoEffectJournal` | `IEffectJournal` | D1; `invalidate()` in the repair command's transaction |
| `PdoBehaviourWorkflowRepository` | `IBehaviourWorkflowRepository` | D10; row + meta in one transaction |
| `PdoWorkItemRepository` | `IWorkItemRepository` | D10; upsert on the natural key |
| `PdoWorkflowIgnitionLedger` | `IWorkflowIgnitionLedger` | D10; primary key gate, 1062 = lost claim |
| `PdoJobStore` | `IWakeupScheduler`, `ITransport` | intents in the process transaction; one deliver job per fact; `claiming()` view |
| `PdoDeliveryWorker` | `IDeliveryWorker` | the drain's delivery stage over `deliver` jobs; handler backoff, ledger-counted budget |
| `FactClassRecordingEventBus` | `IIntegrationEventBus` | core outbox bus plus the fact class on the row |
| `MySqlNamedLock` | `IProcessLock` | `GET_LOCK('ddd:'+sha1(consumer\|tenant\|id))`, fail-closed; wrap in `ReentrantProcessLock` |
| `PdoOperatorView` | `IOperatorView` | core `PortOperatorView` plus the sources below; `to_arrays()` for rendering; `repair()` / `repair_item()` |
| `PdoJobsOperatorSource` | `IOperatorItemSource` | failed wakeups (layer `wakeup`) and deliver jobs (layer `delivery`) |
| `PdoLedgerOperatorSource` | `IOperatorItemSource` | failing or exhausted subscribers (layer `delivery`, key `subscriber@event_id`) |
| `DurableRuntime` | (composition root) | see above |

Tests: `vendor/bin/phpunit -c packages/ddd-core/phpunit.pdo.xml` against MySQL 8, every case with `ATTR_EMULATE_PREPARES` false and true.
