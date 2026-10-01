# tangible/ddd-symfony

Symfony 7.4 host for `tangible/ddd-core` on Postgres 16 (register sections 1.2, 3.2-3.8, 5.1-5.3).
Install and configure: [examples/symfony/README.md](../../examples/symfony/README.md).

| Namespace | Contents |
|---|---|
| `Bundle` | `TangibleDddBundle` (configuration, service wiring, consumer registration and HostDefaults at boot) |
| `DependencyInjection` | handler and `handle()` locators, compile-time subscription map, domain-listener map, Messenger health check, `#[AsIntegrationListener]`, `#[AsDomainEventListener]` |
| `Persistence` | `DbalTransactionBoundary`, `DbalPostgresOutboxStore`, `DbalRelayPauseStore`, `DbalDeliveryLedger`, `DbalOutboxAdministration`, `DbalProcessStore`, `DbalWakeupScheduler`, D10 `DbalBehaviourWorkflowRepository` / `DbalWorkItemRepository` / `DbalWorkflowIgnitionLedger`, D1 `DbalEffectJournal` (`IEffectJournal`, with `invalidate`), `EntityManagerSession`, `PersistenceConflict`, `ConnectionTopology`, `PostgresSchema` |
| `Lock` | `PostgresAdvisoryProcessLock` (session `pg_try_advisory_lock`, register 5.2) |
| `Messenger` | `IntegrationFactMessage`, `MessengerFactTransport` (ITransport), `IntegrationFactHandler`, `ProcessWakeupMessage`, `ProcessWakeupHandler` |
| `Runtime` | `CompiledSubscriptionRegistry`, `DddRuntimeReset`, `Relay` (the core relay step), `Wakeup\WakeupRelay` (due intents → `ddd_wakeups`, stranded scan), `Wakeup\PostgresNotifyRelayWakeup` / `PostgresListenWaiter` (D14), `SymfonySignalDispatcher`, D5 actor providers |
| `Ops` | D9 operator-view sources: `DbalLedgerOperatorSource` (delivery), `DbalWakeupOperatorSource` (wakeup), `MessengerFailureTransportSource` (transport); the view itself is core `PortOperatorView` (service `tangible_ddd.operator_view`, alias `IOperatorView`) |
| `Console` | `ddd:relay`, `ddd:schema:dump`, `ddd:ops:list`, `ddd:ops:dlq:list`, `ddd:ops:dlq:replay`, `ddd:ops:dlq:retry`, `ddd:ops:dlq:discard`, `ddd:ops:stranded`, `ddd:ops:pause`, `ddd:ops:resume` |

Schema: `schema/postgres/*.sql` (plain, idempotent; `{{prefix}}` = `tangible_ddd.table_prefix`).

## Schema evolution (append-only, L5)

- `ddd:schema:dump` prints every file in number order, each headed by
  `-- tangible/ddd-symfony schema/postgres/<file>`. Copy it into the host's
  migrations (Doctrine Migrations `addSql()`, a SQL file).
- A shipped file never changes. `schema/postgres/released.txt` lists each one
  with the digest of its statements, and `PostgresSchemaTest` fails if a listed
  file's statements change (comments and whitespace do not count) or if a file
  is not listed.
- A schema change is the next numbered file (`NNN_what.sql`, contiguous) with
  idempotent statements only (`ALTER TABLE ... ADD COLUMN IF NOT EXISTS`,
  `CREATE ... IF NOT EXISTS`; no `DROP TABLE` / `DROP INDEX`), plus its
  `released.txt` line (`PostgresSchema::digest()`).
- Upgrading: `bin/console ddd:schema:dump --since=NNN`, where NNN is the last
  file the host already applied, is the host's next migration.

## Configuration notes

- `consumer.version` is optional. Absent, `null`, `''`, or an env placeholder
  that resolves to null at runtime (`'%env(default::APP_VERSION)%'` with
  `APP_VERSION` unset) all mean `'0.0.0'`, both for the registered consumer and
  for the audit environment's `app` key (L4). Before wave 4 a null version was a
  `TypeError` at the first command.

## Operator view (D9)

`bin/console ddd:ops:list [--layer=relay|delivery|wakeup|process|workflow|transport] [--format=json]`
lists every failure the host holds, one row per item, ordered by layer and then
oldest first, with attempts against the layer's budget and the repairs that
apply:

| Layer | Source | Budget | Repairs |
|---|---|---|---|
| `relay` | outbox DLQ (`IOutboxAdministration`) | the row's `max_attempts` | `ddd:ops:dlq:retry` / `replay` / `discard` |
| `delivery` | `ddd_delivery_ledger` pairs that failed or are exhausted | `delivery.budget` | none (Messenger is retrying, or the compensation ran) |
| `wakeup` | `ddd_wakeups` intents that failed | 10 | `rearm` when exhausted (`ddd:ops:stranded --rearm`) |
| `process` | stranded `running` processes (`IProcessStore::findStranded`) | - | `ddd:ops:stranded --resume` / `--fail` |
| `transport` | the Messenger failure transport (`messenger.failure_transport`) | - (the ledger counts it) | `messenger:failed:retry` / `remove` |

The same view is the `IOperatorView` service for a host's own admin page.

## Processes and wakeups

- `ProcessRunner` (service `tangible_ddd.process_runner`, public) runs on
  `DbalProcessStore`, the reentrant `PostgresAdvisoryProcessLock`,
  `DbalWakeupScheduler` and the transaction boundary.
- `tangible_ddd.process.inband_start` (the register's `ddd.process.inband_start`,
  default `false`): the runner is built with `StartMode::Deferred`, so `start()`
  persists the process and a `Continue` intent in the caller's transaction (also
  inside a command), takes no process lock, and the first step runs in a worker.
  `true` maps to `StartMode::InBand` (first step in-band, under the advisory lock)
  and is refused at boot on a pooled DSN.
- Workers: `bin/console ddd:relay` (outbox relay, wakeup projection, stranded
  scan, LISTEN wakeup) and `bin/console messenger:consume ddd_facts ddd_wakeups`,
  both on a **direct** (non-pooled) connection. `tangible_ddd.process.pooled_connection:
  refuse` makes the advisory lock and the LISTEN waiter refuse a pooled DSN
  (default `warn`).
- Intent rows (`ddd_wakeups`) are the source of truth; the `ddd_wakeups`
  transport is a projection with Messenger retries off. A wake that fails on a
  lock, a version fence or a transient DB error is retried (2 s x 2^n, 10
  attempts); then, or on any other error, the intent is kept as exhausted for
  `ddd:ops:stranded`.
- `ddd:ops:stranded --resume|--fail` dispatch core's
  `Application\Process\Repair\ResumeStrandedProcess` / `FailStrandedProcess`
  (WP8-10) on the command bus; the core handlers refuse a process that is not
  stranded or whose lock is held.

## Wave 4: D1, D3, D7, D10, D13, D14 on this host

- **D3** `ddd_process_waits` holds one row per `LongProcess::await_routes()`
  route (fact class, await key): a keyed `AwaitEvent` by its key, an `AwaitAny`
  by each branch class, a keyed `AwaitAll` by each missing key, an `AwaitAlarm`
  none. `DbalProcessStore` matches a fact's parents and interfaces and declares
  `IMatchesFactAncestry`. Precheck and the dynamic `AwaitAll` are core's and
  run unchanged here.
- **D7** an alarm is one `timeout` row in `ddd_wakeups`, `due_at` the absolute
  UTC instant fixed at suspension (no chain of short timers, no Messenger
  `DelayStamp`); `ddd:relay` projects it when due.
- **D1** the command bus is act bracket → `EffectMiddleware` (journal
  `tangible_ddd.effect_journal`) → transaction → domain events →
  self-executing → handler.
- **D10** services implementing `IStartsFromFact` (tag `tangible_ddd.workflow`,
  autoconfigured) get one core `WorkflowIgniter` subscriber per `#[StartsOn]`
  fact; `DbalWorkflowIgnitionLedger` is the core `IWorkflowIgnitionLedger`.
- **D14** outbox appends and wakeup intents `NOTIFY` in their transaction;
  `ddd:relay` waits in `LISTEN` and falls back to its poll interval.
- `LongProcess` subclasses loaded as services are autoconfigured with
  `ddd.long_process`; `IReturningCommandHandler` with the command-handler tag (L1).

Usage, including D13 (cause, process id, step index): [examples/symfony/README.md](../../examples/symfony/README.md).
The E section 10 reference scenario is `tests/Kernel/ReferenceScenarioTest.php`.

## Tests

```bash
composer install
vendor/bin/phpunit                          # unit + integration + kernel + conformance
vendor/bin/phpunit --testsuite unit         # no database
vendor/bin/phpunit --testsuite conformance  # the shared ddd-conformance scenarios on sf
vendor/bin/phpunit --group relay.lease-fencing   # one scenario id
```

Integration, kernel and conformance suites need Postgres 16. `DDD_SF_PG_URL`
defaults to `pgsql://postgres:ddd@127.0.0.1:55432/ddd_w2_symfony_adapters`; point
it at a database of your own when other runs share the server (the lock tests
also start a child `php` process that holds an advisory lock). The bootstrap
creates that database if it is missing. Integration and kernel tests drop and
re-create their tables; each conformance test gets a fresh Postgres schema
(`ddd_conf_sf_<hash>`) that is dropped afterwards. Nothing runs inside a
per-test transaction.

The conformance host is `tests/Conformance/SfHostFixture.php`; the scenarios
come from `tangible/ddd-conformance` (require-dev). Since wave 3 it runs every
id due on sf by wave 3 (15 + 23, pinned by `SfCatalogueTest`):

- workers: worker 1 is the fixture's connection, worker 2 a second DBAL
  connection (another advisory-lock session); `drainOnce()` is one pass of
  `ddd:relay` plus `messenger:consume ddd_facts ddd_wakeups`;
- fresh processes: `tests/Conformance/bin/fresh-process.php`, a separate `php`
  process attached to the test's schema (killed with SIGKILL where a scenario
  says so);
- web requests: process-lock acquires go to a pooled-DSN lock with
  `pooled_connection: refuse`, and the in-band boot refusal is the real
  `TestKernel` boot.

### Sibling packages are copied, not linked

`tangible/ddd-core` and `tangible/ddd-conformance` come from path repositories
with `"symlink": false` (report F section 3: a copy catches files that exist
only through the monorepo). `vendor/` therefore keeps the copy made at the last
install. After editing `packages/ddd-core` or `packages/ddd-conformance`,
refresh it before running the tests here:

```bash
composer refresh-siblings   # = composer update tangible/ddd-core tangible/ddd-conformance
```

Without it the suite silently runs against the old copy.
