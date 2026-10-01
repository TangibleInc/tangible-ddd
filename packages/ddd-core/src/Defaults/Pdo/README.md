# TangibleDDD\Defaults\Pdo

The plain-PHP default adapter set of ddd-core (register 3.3). **MySQL 8 tested; other drivers untested.** MariaDB is not a claimed target.

The host owns the database connection. It creates the `PDO` (with `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`), wraps it once in `PdoConnection`, and passes that same object to every adapter and to its own repositories, so one transaction covers the domain write and the outbox row. Nothing here opens a connection, parses a DSN, runs migrations, loops or sleeps.

```php
$db = new PdoConnection($pdo);                 // the host's PDO, ERRMODE_EXCEPTION
(new SchemaCheck($db, 'app_'))->assert();      // verifies; never creates
```

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
| `PdoJobStore` | `IWakeupScheduler`, `ITransport` | intents in the process transaction; one deliver job per fact |
| `MySqlNamedLock` | `IProcessLock` | `GET_LOCK('ddd:'+sha1(consumer\|tenant\|id))`, fail-closed; wrap in `ReentrantProcessLock` |
| `PdoOperatorView` | (operator view) | relay, delivery, wakeup and process layers as arrays |

Tests: `vendor/bin/phpunit -c packages/ddd-core/phpunit.pdo.xml` against MySQL 8, every case with `ATTR_EMULATE_PREPARES` false and true.
