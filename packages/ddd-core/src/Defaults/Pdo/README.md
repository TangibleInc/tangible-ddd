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
$runtime->queryBus()->handle($query);
$report = $runtime->drain(200, 50);       // DrainReport; $runtime->drainer() is the core Drain
$runtime->operatorView()->toArrays();     // IOperatorView, array form for rendering
```

- Tables are `{prefix}_ddd_*`, where prefix is `$consumer->prefix()`. Apply the schema with `SchemaSql::statements($prefix . '_')`.
- The command bus runs Correlation, then Transaction (`PdoTransactionBoundary`), then DomainEventsPublish (local `OrderedListenerDispatcher` plus the outbox bus, which records the fact class through `FactClassRecordingEventBus`), then SelfExecuting, then the handler. The query bus runs SelfExecuting, then the handler.
- The consumer is registered in `ConsumerRegistry`, so `->send()` and fact names route to it by the identity's namespace.
- `SubscriptionRegistrar` takes the listeners (at `LISTENER`) and each process's `#[StartsOn]` (at `IGNITION`) and `#[Awaits]` (at `RESUME`).
- `ProcessRunner` runs on `PdoProcessStore`, a `ReentrantProcessLock` over `MySqlNamedLock` (guarded by `RuntimeReset`), and `PdoJobStore`. The start mode is HostDefaults' `StartMode`, or `Deferred` when none is set, so a command can start a process atomically with its own writes.
- `Drain` runs four stages: relay (`OutboxProcessor`, submit and accept in one transaction), then deliveries (`PdoDeliveryWorker` through `IntegrationDelivery` and `PdoDeliveryLedger`), then due wakeups (the runner), then the stranded scan (the runner).
- `$handlers` is either a PSR-11 container or an array. In the array, a command or query class key maps to its handler, which is a callable or an object with `handle()`. Any other key is a service: a `\Closure` is a lazy factory, and an object is used as the instance. The runtime's own services resolve first: `CommandBus`, `EventsUnitOfWork`, `ProcessRunner`, `IHostConnection`, `ITransactionBoundary`, `IClock` and `IConsumerIdentity`.

`examples/plain-php-durable/` is its acceptance fixture.

## Schema

`packages/ddd-core/schema/mysql8/*.sql` are plain `CREATE TABLE IF NOT EXISTS` statements (InnoDB, utf8mb4, DYNAMIC rows) with a `{{prefix}}` placeholder. Apply them with your own tooling; `SchemaSql::dump('app_')` prints them with the prefix substituted. `SchemaCheck` reports a missing table, column, unique key or a non-InnoDB engine.

| File | Table | Used by |
|---|---|---|
| 001_outbox.sql | `{prefix}ddd_outbox` | `PdoOutboxStore`, `PdoOutboxAdministration` |
| 002_dlq.sql | `{prefix}ddd_dlq` | the same |
| 003_relay_pauses.sql | `{prefix}ddd_relay_pauses` | `PdoPauseStore` |
| 004_delivery_ledger.sql | `{prefix}ddd_delivery_ledger` | `PdoDeliveryLedger` |
| 005_processes.sql | `{prefix}ddd_processes` | `PdoProcessStore` |
| 006_jobs.sql | `{prefix}ddd_jobs` | `PdoJobStore` (wakeup intents and deliver jobs) |

All times are UTC `DATETIME(6)` written from `IClock`, never `NOW()`.

## Adapters

| Class | Port | Notes |
|---|---|---|
| `PdoConnection` | `IHostConnection` | ints bound with `PARAM_INT`; `isDuplicateKey` = MySQL 1062 only |
| `PdoTransactionBoundary` | `ITransactionBoundary` | rejects nesting by default; `NestedPolicy::Savepoint` opt-in |
| `PdoOutboxStore` | `IOutboxStore` | claim = `FOR UPDATE SKIP LOCKED` + `claim_token` lease, outside any transaction |
| `PdoOutboxAdministration` | `IOutboxAdministration`, `IOutboxRowIds` | retry removes the DLQ entry; replay keeps `event_id` |
| `PdoPauseStore` | `IRelayPauseStore` | fnmatch selectors, applied inside the claim |
| `PdoDeliveryLedger` | `IDeliveryLedger` | `last_error`, `exhausted_at` |
| `PdoProcessStore` | `IProcessStore` | `UNIQUE (process_class, ignition_key)`, `quarantine_reason`, version fencing |
| `PdoJobStore` | `IWakeupScheduler`, `ITransport` | intents in the process transaction; one deliver job per fact; `withClaimKinds()` view |
| `PdoDeliveryWorker` | `IDeliveryWorker` | the drain's delivery stage over `deliver` jobs; handler backoff, ledger-counted budget |
| `FactClassRecordingEventBus` | `IIntegrationEventBus` | core outbox bus plus the fact class on the row |
| `MySqlNamedLock` | `IProcessLock` | `GET_LOCK('ddd:'+sha1(consumer\|tenant\|id))`, fail-closed; wrap in `ReentrantProcessLock` |
| `PdoOperatorView` | `IOperatorView` | core `PortOperatorView` plus the sources below; `toArrays()` for rendering |
| `PdoJobsOperatorSource` | `IOperatorItemSource` | failed wakeups (layer `wakeup`) and deliver jobs (layer `delivery`) |
| `PdoLedgerOperatorSource` | `IOperatorItemSource` | failing or exhausted subscribers (layer `delivery`, key `subscriber@event_id`) |
| `DurableRuntime` | (composition root) | see above |

Tests: `vendor/bin/phpunit -c packages/ddd-core/phpunit.pdo.xml` against MySQL 8, every case with `ATTR_EMULATE_PREPARES` false and true.
