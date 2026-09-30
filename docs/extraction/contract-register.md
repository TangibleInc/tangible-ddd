# tangible-ddd extraction: contract register

- Status: wave-0 output, 2026-10-01. It is the single reference that wave 1-4 authors build against. A change to anything marked **frozen** needs a register edit before the code edit.
- Source: `/Users/titustc/tgbl/repos/tangible-ddd`, branch `extraction/ddd-packages`, pinned `598858c` (0.6.6). All `path:line` citations are against that tree.
- Inputs: the wave-0 reports [A core boundary](wave0/A-core-boundary.md), [B WordPress compatibility](wave0/B-wordpress-compat.md), [C durable execution](wave0/C-durable-execution.md), [D consumer inventory](wave0/D-consumer-inventory.md), [E Symfony host](wave0/E-symfony-host.md), [F packaging and harness](wave0/F-packaging-harness.md); the TXP working spec `txp/.docs/specs/tangible-ddd-extraction.md` (and its 2026-09-30 review, findings 1-9); TXP `module-map.md` (D1-D14, slice list).

## 0. Fixed operator decisions (not re-opened here)

1. No bundled MySQL runtime, worker daemon or migration framework in core. The host passes its own connection (the "`$db` conn somewhere"). Core ships ports, in-memory doubles, one thin PDO adapter set that takes that connection, plain schema SQL, and a `runOnce()` drain the host schedules from cron or end of request. This supersedes spec M3a and review finding 4.
2. MySQL 8 is tested. MariaDB is not a claimed target. Postgres is tested through ddd-symfony only.
3. The three verified bugs are fixed on the extraction branch. A 0.6.x hotfix branch is prepared but not released.
4. Tactician stays. Existing WordPress consumers keep working during migration.
5. Nothing is published, tagged or deployed. No consumer dependency changes.

## 0.1 Where the reports disagreed, and what the code says

| # | Topic | Positions | Resolution (with evidence) |
|---|---|---|---|
| X1 | Compatibility window | B and F keep 0.2.x in scope because tangible-reporting pins `^0.2.5`. D says the window is 0.6.2-0.6.6. | **D.** Reporting master has no `TangibleDDD` references. Its `feature/tangible-ddd` branch wires `CorrelationContext` and `CommandAuditMiddleware`, both deleted in 0.4.0 (`50fcd29`), so it cannot boot next to any live 0.6 consumer today: the winner's prepended autoloader serves all `TangibleDDD\` classes from the winning copy (`tangible-ddd.php:274-297`). The window is `>=0.6.2 <0.7`, frozen at the 0.6.5 contract. 0.2.x is unsupported (section 7). This also closes review finding 1. |
| X2 | PHP floor | A: core could claim 8.1. D and F: 8.2. | **8.2 for every package.** The locked Symfony 7.4 graph already needs 8.2 (`composer.lock`, `php >=8.2` on symfony/config, dependency-injection, filesystem, var-exporter); PHPUnit 11 refuses to start on 8.1 (F section 1); no consumer runs 8.1 (D 3.2). Claiming 8.1 for core alone buys nothing and adds a CI leg. |
| X3 | Postgres process lock | C: portable contract is a row lease with fencing; session locks optional. E: session `pg_try_advisory_lock`, re-read under the lock. | **Session advisory lock plus version-fenced saves** (section 5.2). The wake spans several commits (`ProcessRunner.php:619-628`) and nests the lock (`ProcessRunner.php:345` then `:249` via `with_process`), so a transaction-scoped lock cannot cover it. Workers need a direct, non-pooled connection anyway for `LISTEN` (D14), so the session lock adds no new prerequisite. C's concern about a stale owner is real (a reconnect from `doctrine_ping_connection` silently drops a session lock), so every process save is fenced by a `version` column. That is C's fencing without the lease renewal machinery. |
| X4 | Owner of the Symfony-DI compiler passes | A: three options. E: ddd-symfony, with a WP wrapper. F: an optional bridge namespace inside core. | **Core owns the FQCNs** `TangibleDDD\Infra\DependencyInjection\{DDDCompilerPasses,LongProcessCatalogPass}` as a fenced bridge layer; `symfony/dependency-injection` is `suggest` + `require-dev` in core. Both wp and symfony already need them (`ddd-wordpress/self/index.php:17-19`, datastream uses `DDDCompilerPasses` per D section 4), and the one-owner rule forbids duplicates. Deptrac forbids any portable core layer from importing the bridge. |
| X5 | Package layout vs the legacy self-consume path | Spec and F: sources under `packages/`. B5: the winner's registered path must contain `ddd-wordpress/self/index.php`, because a legacy copy's `tangible_ddd_self_consume` requires it (`tangible-ddd.php:372`) and a missing file is an uncatchable fatal. | **Both.** Sources move to `packages/*`. The root distribution keeps a forwarding shim at `ddd-wordpress/self/index.php` for the whole compatibility window (section 1.1). |
| X6 | `IDDDConfig` owner | A: core owns it for the compatibility window. B, D, E: split identity out, keep `IDDDConfig` as the WP contract. | **Core owns the interface FQCN unchanged, and gains a parent** `IConsumerIdentity` (section 3.1). `IDDDConfig` is a pure interface (no WP calls, `Infra/IDDDConfig.php:11-70`), and 17 `ddd-src` files type-hint it, including constructors that shipped compiled containers call with fixed arity (D F5). Moving it to wp would make core depend on wp. Portable code types against `IConsumerIdentity`; the WP concretes `DDDConfig`, `Config` and `ConsumerTables` move to wp. |
| X7 | Ignition uniqueness | C: `UNIQUE (process_class, ignited_by_event_id)` on the process table, after deduping existing rows. B22: a new additive ignitions table. | **Split by host.** wp uses an additive `{prefix}_process_ignitions` table with UNIQUE, backfilled with `INSERT IGNORE` in id order, so existing duplicate sagas are kept and reported, never deleted, and the migration cannot fail on them. pdo-default and symfony are fresh schemas and put the unique constraint on the process table. The core contract (`insertIgnited` returns `Inserted` or `AlreadyIgnited`) is identical. |
| X8 | Per-subscriber delivery | C: fan out one AS action per subscriber, or catch per callback plus a ledger. E: rely on Messenger `HandledStamp` skipping, else one message per (fact, subscriber). | **One delivery per fact, run by a core invoker that walks subscribers in phase order with a per-(subscriber, event_id) delivery ledger** (section 5.1). This keeps the ignition-before-resume ordering (`ProcessRunner.php:95,180`, priorities 99 and 50; listeners 10), keeps legacy AS action shapes for rollback (B16), and does not depend on unverified Messenger retry behaviour. The HandledStamp spike (E open question 1) becomes an optimisation, not a design input. |
| X9 | Symfony schema delivery | E open question 10: Doctrine Migrations classes or SQL. | **Plain SQL files** under `packages/ddd-symfony/schema/postgres/` plus a `ddd:schema:dump` console command that prints them for the host's own migrations. Consistent with "no migration framework" in core. |
| X10 | Transaction nesting | A, C: open. E: `reject` in production, `savepoint` for tests. | **E.** Default `reject` (throw before the handler when a transaction is already open); `savepoint` opt-in per boundary instance. Today a nested `START TRANSACTION` on MySQL implicitly commits the outer one (`TransactionMiddleware.php:39`). |
| X11 | OutboxConfig factory | B10: keep `from_options` on core FQCN with a host reader, or move the FQCN. A F-23: move the factory to a wp class. | **Core keeps `OutboxConfig` (final, same constructor, `OutboxConfig.php:12-26`) and keeps `from_options(IDDDConfig)` as a delegate** to an options reader that ddd-wp registers at init; with none registered it throws `IncorrectUsageException`. Legacy YAML names `OutboxConfig::from_options` as a factory (`ddd-wordpress/di/services.yaml:48-51`, scaffold `class-ddd-command.php:454-457`), so the method must keep resolving on the same FQCN. |
| X12 | Legacy classes whose constructors carry WP types | D F5/F16: keep legacy classes concrete with 0.6.5 constructors. B9: append optional params, WP defaults from a host registry. | **Rule R2** (section 1.3). If the 0.6.5 constructor names only core types, core keeps the FQCN and may append optional trailing parameters, defaulted from `HostDefaults`. If it names a WP type (`TransactionMiddleware(?wpdb)`, `TransactionMiddleware.php:24`) or has a WP side effect (`IntegrationListener::__construct`, `IntegrationListener.php:26-31`), ddd-wp owns the legacy FQCN as a thin subclass of a new core class. |

Everything else the reports said was consistent, or was about different parts of the code.

---

## 1. Package layout and ownership

### 1.1 Repository layout (one repo, directory per package)

```text
tangible-ddd/                        # Composer name tangible/ddd: the legacy WordPress distribution. VCS URL unchanged.
├── composer.json                    # autoload psr-4 "TangibleDDD\\": ["packages/ddd-core/src/", "packages/ddd-wp/src/"],
│                                    #   "TangibleDDD\\WordPress\\": "packages/ddd-wp/wordpress/", classmap for wordpress/cli, wordpress/self
│                                    # files: ["loader/tangible-ddd-0_7_0.php", "packages/ddd-core/src/Domain/Shared/assert.php"]
│                                    # replace: {"tangible/ddd-core": "self.version", "tangible/ddd-wp": "self.version"}
├── tangible-ddd.php                 # plugin header + newest-wins loader (direct include when activated as a plugin, "P")
├── loader/tangible-ddd-0_7_0.php    # version-unique files entry; require_once __DIR__.'/../tangible-ddd.php' (B1, D-2)
├── ddd-wordpress/self/index.php     # forwarding shim to packages/ddd-wp/wordpress/self/index.php (B5); nothing else under ddd-wordpress/
├── packages/
│   ├── ddd-core/                    # tangible/ddd-core
│   │   ├── composer.json            # php >=8.2, league/tactician ^2.0-rc1, psr/container ^1.1|^2.0, psr/log ^1|^2|^3
│   │   │                            # suggest: ext-pdo, symfony/dependency-injection (bridge only)
│   │   ├── src/
│   │   │   ├── Domain/ Application/ Infra/     # the 131 "keep" files + core halves of the 15 splits, same FQCNs
│   │   │   ├── Runtime/             # new ports and portable runtime: clock, HostDefaults, delivery invoker, runOnce drain, RuntimeReset
│   │   │   ├── Testing/             # in-memory doubles + IntegrationConformance (existing)
│   │   │   └── Defaults/Pdo/        # the pdo-default adapter set (deptrac-fenced; portable layers never import it)
│   │   ├── schema/mysql8/*.sql      # plain CREATE TABLE IF NOT EXISTS; applied by the host
│   │   └── tests/{Unit,Pdo}/
│   ├── ddd-wp/                      # tangible/ddd-wp (a layering artifact; WP plugins keep requiring tangible/ddd)
│   │   ├── composer.json            # tangible/ddd-core (exact self.version), woocommerce/action-scheduler ^3.9,
│   │   │                            # symfony/dependency-injection ^7.4, symfony/config ^7.4, symfony/yaml ^7.4 (require, F-5), makinacorpus/query-builder ^1.6
│   │   ├── src/                     # the 20 moved FQCNs + WP halves of the splits, same FQCNs (TangibleDDD\...)
│   │   ├── wordpress/               # today's ddd-wordpress/: procedural files, Admin/, cli/, self/, di/, migrations
│   │   └── tests/{Unit,Integration}/
│   ├── ddd-symfony/                 # tangible/ddd-symfony (not in root autoload, not in replace, export-ignored)
│   │   ├── composer.json            # tangible/ddd-core, doctrine/dbal ^4, symfony/messenger ^7.4, symfony/framework-bundle ^7.4
│   │   ├── src/                     # TangibleDDD\Symfony\{Bundle,DependencyInjection,Messenger,Persistence,Lock,Console,Runtime}
│   │   ├── schema/postgres/*.sql
│   │   └── tests/{Kernel,Integration}/
│   └── ddd-conformance/             # tangible/ddd-conformance: dev-only, requires phpunit; shared scenario suite (section 4)
├── examples/{plain-php,plain-php-durable,symfony}/
├── tests/{Unit,Integration}/        # today's root suites, kept green through every move batch
├── tests/{Compat,Loader,harness}/   # clean-install, loader fixtures, Docker DB harness
└── .gitattributes                   # export-ignore tests/ tools/ docs/ examples/ packages/ddd-symfony/ packages/ddd-conformance/ packages/*/tests/
```

Namespaces: portable core keeps `TangibleDDD\` (D F6: every consumer names these FQCNs). New core runtime types go under `TangibleDDD\Runtime\`. The PDO default is `TangibleDDD\Defaults\Pdo\`. Symfony adapter types are `TangibleDDD\Symfony\`. New WP adapter types are `TangibleDDD\WordPress\Adapter\`. Composer supports one PSR-4 prefix mapped to several directories, and each file exists in exactly one of them.

### 1.2 Ownership by package

| Package | Owns | Must not contain |
|---|---|---|
| ddd-core | Domain, CQRS, eventing, correlation/trace, consumer registry, value types, process and workflow algorithms, every port in section 3, in-memory doubles, `IntegrationConformance`, the DI bridge (X4) | any WP symbol, `$wpdb`, `global $`, Action Scheduler, `TangibleDDD\WordPress\*`, Symfony outside the bridge, `makinacorpus/*`, an autoload `files` entry other than `Domain/Shared/assert.php`, a loader, a registry, a connection factory |
| ddd-core `Defaults/Pdo` (owner tag pdo-default) | adapters over a host-supplied connection, MySQL 8 schema SQL, `SchemaCheck`, `DurableRuntime::compose()`, `Drain::runOnce()` | a DSN parser, credential lookup, `new PDO`, a migrator, a loop, signal handling, a CLI binary |
| ddd-wp | the WP halves: wpdb repositories and ports, Action Scheduler publisher and scheduler, WP hook facades, the procedural `TangibleDDD\WordPress\*` API, admin dashboard, WP-CLI, self-consumer, migrations (`dbDelta` ledger), legacy FQCNs per rule R2 | any use of `Defaults/Pdo` (B24, D-7) |
| ddd-symfony | bundle, compiler passes that build service locators (E S2), DBAL ports, Postgres lock, Messenger publisher and handlers, NOTIFY wakeup, console (`ddd:relay`, `ddd:ops:*`, `ddd:schema:dump`), worker reset | its own copy of any core FQCN or the DI passes |
| tangible/ddd (root) | loader, version-unique files entry, self-consume shim, `compat/` alias map (section 1.4), the release artifact | source files of its own beyond those |

### 1.3 Class-ownership rules (frozen)

- **R1 One owner.** Every FQCN has exactly one owning package. Aliases and facades for moved names live in ddd-wp or the root `compat/` map, never as a duplicate PSR-4 declaration.
- **R2 Legacy constructors.** The 0.6.5 constructor of every class in the ABI freeze list (B section 5, D section 4) stays callable exactly as the shipped compiled containers call it (LMS 0.12.0, quiz 0.7.0, certificates 0.3.1: `CompiledContainer.php:384-528`, e.g. `new TransactionMiddleware()`, `new ProcessRunner(...)`). If the legacy constructor names only core types, core keeps the FQCN and may only append optional trailing parameters; a `null` argument resolves from `TangibleDDD\Runtime\HostDefaults`, which ddd-wp populates at init and which is empty elsewhere. If it names a WP type or has a WP side effect, ddd-wp owns the legacy FQCN as a thin subclass of a new core class.
- **R3 Interfaces consumers implement never gain abstract methods** (`IDDDConfig`, `IOutboxRepository`, `IProcessRepository`, `IBehaviourWorkflowRepository`, `IOutboxPublisher`, `ICommandHandler`, `IQueryHandler`, `IAwaitMechanism`, ...). New capability goes on new interfaces (`IOutboxStore`, `IProcessStore`), and core wraps a legacy implementation in a degraded adapter where needed. LMS ships its own Doctrine `IOutboxRepository` (`lms:src/Infrastructure/Persistence/Doctrine/OutboxRepository.php:35`) and cred its own `IProcessRepository`.
- **R4 Persisted and subscribed strings do not change**: `DomainEvent::action()`, `integration_action()`, hook names, AS groups, option names, table names and columns, AS arg shapes, envelope keys `__correlation_id/__sequence/__event_id` (B15-B17, A F-12).
- **R5 Schema changes are additive**: new tables or nullable/defaulted columns only, one ledger (`DDD_SCHEMA_VERSION`, `ddd-wordpress/migrations.php:39`) shared with the hotfix line; the 0.6.7 hotfix changes no schema (B20).

`HostDefaults` is process-static and boot-time only; `RuntimeReset` never clears it (A F-18).

### 1.4 Disposition of the 15 split classes

| Legacy FQCN | Owner after split | Core form | WP form |
|---|---|---|---|
| `Application\Persistence\TransactionMiddleware` | **wp** (ctor `?wpdb`) | new `Application\Persistence\TransactionalCommandMiddleware(ITransactionBoundary)` | `TransactionMiddleware(?wpdb $wpdb = null)` extends it with `WpdbTransactionBoundary` |
| `Application\EventHandlers\IntegrationListener` | **wp** (ctor side effect) | new abstract `Application\EventHandlers\IntegrationTranslator` with the same protected `get_event_class()` / `get_command()` plus public final `event_class()` / `translate()` | `IntegrationListener extends IntegrationTranslator`, constructor still calls `integration_listener()` |
| `Application\Correlation\CorrelationMiddleware` | core | same ctor + optional `?IAuditSink, ?IActorProvider, ?IAuditPolicy, ?IEnvironmentProvider` | `WpdbAuditSink`, `WpActorProvider`, `WpEnvironmentProvider` registered in HostDefaults |
| `Application\Process\ProcessRunner` | core | same ctor + optional `?IProcessLock, ?IProcessStore, ?IWakeupScheduler, ?ISubscriptionRegistry, ?ITransactionBoundary, ?IClock` | GET_LOCK lock, AS wakeup relay, `add_action` subscription registrar |
| `Infra\Services\OutboxProcessor` | core | same ctor + optional `?ISubscriberProbe, ?LoggerInterface, ?IClock`; `process_batch()` is the relay step of `runOnce` | `has_action` probe, `error_log` logger |
| `Infra\Services\OutboxIntegrationEventBus` | core | same ctor + optional `?IFactObserver` (errors caught) | touches indexer |
| `Application\Infrastructure\InfrastructureEvent` | core | `dispatch()` routes to `IInfrastructureSignalDispatcher` from HostDefaults; default logs via PSR-3, never silent | hook facade firing both `{prefix}_x` and `tangible_ddd_x` |
| `Application\Outbox\OutboxConfig` | core | unchanged value; `from_options()` delegates per X11; add `from_array()` | options reader |
| `Application\Commands\Command` | core | drop the `SelfConsumer\di()` override (`Command.php:24-26`); the self-consumer registers in `ConsumerRegistry` at init | self-consumer registration |
| 4 repair handlers | core | orchestration over `IOutboxAdministration` | `WpdbOutboxAdministration` |
| `Application\BehaviourWorkflows\WorkflowHandler` | core | drop `is_multisite` stamping (`:209`) and `error_log` (`:127`) | repositories stamp `blog_id` |
| `Infra\IDDDConfig` | core (interface) | `extends IConsumerIdentity` (X6) | `DDDConfig`, `Config` (moved) |

The 20 whole-file moves to wp keep their FQCNs (A section 3.8): `TangibleFieldsRenderer`, `WordPressActionHandler`, `WPErrorException`, `ConsumerTables`, `Config`, `DDDConfig`, the four wpdb repositories, `WordPressRepository`, `ISelect`, `QueryBuilderSelect`, `ISearchableRepository`, `RepositorySearchResult`, `ActionSchedulerOutboxPublisher`, `RoutingOutboxPublisher`, `WordPressEventDispatcher`. The two DI passes stay in core (X4), which changes A's table by two rows. **File-level move map: [A section 3](wave0/A-core-boundary.md) is authoritative for all 166 `ddd-src` files, with the X4 and R2 amendments above.**

### 1.5 Autoload and release artifact

- ddd-core autoload: PSR-4 `TangibleDDD\` → `src/`; files: `src/Domain/Shared/assert.php` only. `require vendor/autoload.php` defines no WP function, no `Tangible_DDD_Versions`, no `TANGIBLE_DDD_VERSION`, and adds no autoloader beyond Composer's (A F-01, F-3).
- Root `tangible/ddd` artifact (the only thing WP plugins install): root files plus `packages/ddd-core/` and `packages/ddd-wp/` from the same commit (the matched pair, B D-3, D F13), `loader/`, the shim. The winner's prepended autoloader maps `TangibleDDD\` to its own `packages/ddd-core/src` and `packages/ddd-wp/src`, consults the `compat/` alias map, and logs every fall-through to another copy as a diagnostic (B7, F-13). It does not throw, because `class_exists` probes run through it (`ddd-wordpress/hooks.php:126`).
- The files entry is version-unique per release so Composer's cross-vendor dedup (`vendor/composer/autoload_real.php:37-45`) cannot suppress a newer copy (B1). `LoaderIdentityTest` asserts the entry filename, the constant, the register literal, the function slugs and every `packages/*/composer.json` version agree.
- Split mirrors (`ddd-core`, `ddd-symfony`; `ddd-wp` only as a layering artifact) come from `splitsh-lite --prefix=packages/<name>`, one version line for all packages (0.7.0). The workflow is written in wave 4 with `workflow_dispatch` only and no secrets. Nothing runs until the operator approves publishing.
- TXP consumes before publishing through Composer path repositories to `packages/ddd-core` and `packages/ddd-symfony`, with `symlink: false` in package CI.
- Packagist already indexes `dev-extraction/ddd-packages` (D F12). See open decision O12.

---

## 2. PHP floor and database support matrix

| Package | PHP | CI legs | Database | How tested |
|---|---|---|---|---|
| ddd-core (portable) | >=8.2 | 8.2, 8.4 | none | unit suite without `wp-stubs.php`; clean-install script |
| ddd-core `Defaults/Pdo` | >=8.2, `ext-pdo` + `ext-pdo_mysql` (host) | 8.2 | **MySQL 8.0** (gating), **MySQL 8.4** (second leg); InnoDB, utf8mb4, DYNAMIC rows | `tests/Pdo` + two-process drain script on Docker `mysql:8.0` / `mysql:8.4` |
| ddd-wp | >=8.2 | 8.2, 8.4 | **MySQL 8.0 / 8.4 via wpdb** | legacy unit suite + WP 7.1.2 integration on Docker MySQL, fresh DB per run |
| ddd-symfony | >=8.2 (Symfony 7.4) | 8.2, 8.4 | **Postgres 16** (gating), 17 second leg; direct (non-pooled) connection for workers | kernel + integration suite on Docker `postgres:16` |
| tangible/ddd (root) | >=8.2 | 8.2, 8.4 | as ddd-wp | everything above that ships in the artifact, plus loader and compat fixtures |

Not claimed: **MariaDB** (any package), Postgres through `Defaults/Pdo`, any other PDO driver, PgBouncer transaction pooling or a Neon `-pooler` endpoint for ddd-symfony workers, PHP 8.1. The `Defaults/Pdo` README says "MySQL 8 tested; other drivers untested". WP sites on MariaDB keep running the SQL that 0.6 already runs; ddd-wp adds no MySQL-8-only syntax to the WP path without a register entry (see O2).

---

## 3. Port signatures

Sketches, PHP 8.2. Method names in new ports are camelCase; existing snake_case interfaces keep their style (R3). Every port lists error behaviour and connection or lifetime rules. Implementations: **mem** (core in-memory double), **pdo**, **wp**, **sf** (symfony).

### 3.1 Identity, clock, ids, service registry

```php
namespace TangibleDDD\Infra;
interface IConsumerIdentity {
  public function prefix(): string;   // stable, [a-z0-9_]+, namespaces tables, locks, keys, hooks
  public function version(): string;
}
interface IDDDConfig extends IConsumerIdentity { /* the 8 existing methods, frozen (Infra/IDDDConfig.php:11-70) */ }

namespace TangibleDDD\Runtime;
interface ITableNames { public function table(string $logical): string; } // pdo/sf: prefix passed to ctor; wp: $wpdb->prefix per call

interface IClock { public function now(): \DateTimeImmutable; }          // always UTC
final class SystemClock implements IClock {}
final class FrozenClock implements IClock { public function advance(string $interval): void {} } // mem

// TangibleDDD\Domain\Shared\Uuid gains:
public static function v5(string $namespace_uuid, string $name): string;   // RFC 4122 vectors in tests (D13)
```

- Clock: never throws. All durable times (`due_at`, `scheduled_at`, `lease_until`) are computed from `IClock` and stored and parsed as UTC explicitly (C bug 3 fix note).
- Service registry: `ConsumerRegistry` stays as is in core (`Infra/Consumers/ConsumerRegistry.php:22-251`), with `add()` widened to `IConsumerIdentity` (a widening parameter type is compatible for all callers). It is boot-time, process-static, and never reset per message. Error: `NoConsumerOwnsClass` when `send()` or `Event::prefix()` runs for an unregistered namespace. Non-registry path: `$bus->handle($command)`. Core exports `CommandBusAware::COMMAND_BUS_ID` and `QueryBusAware::QUERY_BUS_ID = 'tactician.query_bus'` (A F-29). sf registers one consumer (`txp`) at `Bundle::boot()`; bounded contexts are namespaces, not consumers (E S8).
- Service resolution: PSR-11 only. sf passes compiled `ServiceLocator`s for handlers and `handle()` parameters, not `@service_container` (E S2).

### 3.2 Transactions / unit of work

```php
namespace TangibleDDD\Runtime;
enum NestedPolicy { case Reject; case Savepoint; }
interface ITransactionBoundary {
  /** @template T @param callable():T $work @return T */
  public function run(callable $work): mixed;
  public function isActive(): bool;
}
final class NoTransactionBoundary extends \LogicException {}        // ITransactionalCommand with no boundary configured
final class NestedTransactionRejected extends \LogicException {}    // outer tx open and policy Reject
final class TransactionFailed extends \RuntimeException {}          // begin/commit/rollback failed; previous = driver error
```

- `run()` begins, runs `$work`, commits. If `$work` throws, it rolls back and rethrows the **original** exception; a rollback failure is attached as `previous` of a logged secondary, never replacing the original. A failed COMMIT throws `TransactionFailed` and the command reports failure (C13; today `wpdb::query()` false is ignored, `TransactionMiddleware.php:39,43`).
- Connection rules: the boundary wraps the **same** connection the domain repositories and the outbox store use. It never opens, closes or reconfigures a connection. pdo: `PdoTransactionBoundary(IHostConnection)` requires `PDO::ATTR_ERRMODE === ERRMODE_EXCEPTION` and throws a configuration error at construction otherwise (C 7.1). wp: `WpdbTransactionBoundary` over the global `$wpdb` with checked results. sf: `DbalTransactionBoundary(Connection)`, flushing the ORM before commit when configured, and failing container compilation if an `ITransactionalCommand` exists and no boundary is bound (E section 5).
- Middleware: `TransactionalCommandMiddleware` wraps only `ITransactionalCommand`. Without a boundary it throws `NoTransactionBoundary` **before** the handler runs (no silent fallback; `TransactionMiddleware.php:34-36` today). Order is frozen: Correlation → Transaction → DomainEventsPublish → SelfExecuting → handler (`ddd-wordpress/di/tactician.yaml:29-35`). Return values pass through unchanged (D11, A F-13).
- sf: Messenger's `doctrine_transaction` middleware must not wrap a bus that dispatches DDD commands; the bundle health check fails if it does.
- Unit of work: `EventsUnitOfWork` is unchanged and must be the same instance for `SelfExecutingCommandMiddleware` and `DomainEventsPublishMiddleware` (`SelfExecutingCommandMiddleware.php:80-85`).

### 3.3 Host connection (the plain-PHP "`$db` somewhere")

```php
namespace TangibleDDD\Defaults\Pdo;
interface IHostConnection {
  public function execute(string $sql, array $params = []): int;           // affected rows
  public function fetchAll(string $sql, array $params = []): array;        // list<array<string,mixed>>
  public function fetchOne(string $sql, array $params = []): ?array;
  public function lastInsertId(): string;
  public function begin(): void; public function commit(): void; public function rollBack(): void;
  public function inTransaction(): bool;
  public function isDuplicateKey(\Throwable $e): bool;                     // MySQL 1062 / SQLSTATE 23000
}
final class PdoConnection implements IHostConnection { public function __construct(\PDO $db) {} }
```

- The host passes the connection its own repositories already use; that shared connection is the only thing atomicity needs (spec "Ports and standalone defaults"; B24). A CodeIgniter 4 host on its default MySQLi driver implements `IHostConnection` in about 40 lines over `$db->connID` and `transBegin/transCommit/transRollback`, or builds `PdoConnection` only if its domain writes also go through that PDO. The recipe in `examples/plain-php-durable/` shows both.
- Errors: every method throws on failure; nothing returns `false`.

### 3.4 Outbox storage and administration

```php
namespace TangibleDDD\Runtime\Outbox;
final class OutboxRecord { /* event_id, event_type, integration_action, correlation_id, sequence, command_id,
                              payload (array), due_at (UTC), is_unique, payload_signature, max_attempts, blog_id? */ }
final class Claim { public function __construct(public readonly string $event_id, public readonly string $claimToken,
                                                public readonly \DateTimeImmutable $leaseUntil, public readonly OutboxRecord $record, public readonly int $attempts) {} }
interface IOutboxStore {
  public function append(OutboxRecord $r): void;                                    // inside the ambient tx; throws on failure
  /** @return list<Claim> */
  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array; // due, not paused, lease free; SKIP LOCKED
  public function accept(Claim $c, ?string $transportRef): bool;                    // false = lease lost, caller discards
  public function retryLater(Claim $c, string $error, \DateTimeImmutable $nextAt): bool; // attempts = attempts + 1 in SQL
  public function deadLetter(Claim $c, string $error): bool;                        // DLQ insert + status in one tx
}
interface IRelayPauseStore {
  public function hold(string $holder, string $selector, ?\DateTimeImmutable $until): void; // one row per (holder, selector)
  public function release(string $holder, ?string $selector = null): void;
  public function isPaused(string $eventType, \DateTimeImmutable $now): bool;       // exact or wildcard, expiry honoured
}
interface IOutboxAdministration {
  public function deadLetters(int $limit, ?string $after = null): array;
  public function retry(string $event_id, bool $force = false): void;   // pending|dlq only unless $force; refuses a leased row
  public function replay(int $dlqId): void;                             // keeps event_id: resets the original outbox row, resolves the DLQ row, one tx
  public function discard(int $dlqId): void;
  public function purge(\DateTimeImmutable $olderThan): int;            // accepted/completed rows only
  public function stats(): array;
}
```

- Errors: `append` throws, so a failed outbox insert rolls back the command (C14). `accept`, `retryLater` and `deadLetter` are fenced with `WHERE event_id = ? AND claim_token = ?`: 0 rows means the lease was lost, the result is discarded and logged, and nothing throws (C16-C18, E F4). The lease comes from `OutboxConfig::lock_timeout_seconds`, not a literal 300 (C15). Status vocabulary: `pending`, `accepted` (transport has it), `dlq`, `cancelled`; legacy `completed` reads as `accepted` (C19). `cancel_duplicates` touches only unleased `pending` rows with the same payload signature (C26).
- Replay keeps `event_id` (C22). That is possible without relaxing `uniq_event_id` because `move_to_dlq` leaves the original outbox row in place with status `dlq` (`OutboxRepository.php:262-270`) and purge deletes only `completed` rows (`PurgeOutboxHandler.php:26-28`). If the row is gone, replay re-inserts it with the original `event_id`. The legacy handler's new UUID (`ReplayDeadLetterHandler.php:33`) is dropped.
- Legacy bridge: `LegacyOutboxStore(IOutboxRepository)` adapts consumer implementations (LMS Doctrine) with **unfenced** semantics and a logged warning. wp's own `OutboxRepository` implements both interfaces. `get_stats()` stops reading the nonexistent `resolved_at` column (C24).
- Lifetime: stateless per call; uses the host connection; `claim` runs one short transaction of its own and must be called **outside** any open transaction (it throws `NestedTransactionRejected` otherwise; E F13).

### 3.5 Publication, subscription and local dispatch

```php
// existing, frozen: IOutboxPublisher::publish(OutboxEntry $entry, array $wrapped_payload): void
namespace TangibleDDD\Runtime\Delivery;
interface ITransport {
  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string; // transport ref; throws on rejection
  public function sharesConnectionWith(IOutboxStore $store): bool; // true → relay runs submit + accept in one tx
}
enum Phase: int { case Listener = 10; case Ignition = 50; case Resume = 99; }  // frozen ordering (ProcessRunner.php:95,180)
final class Subscriber { public function __construct(public readonly string $id, public readonly Phase $phase,
                                                     public readonly string $eventClassOrMarker, public readonly \Closure $handle) {} }
interface ISubscriptionRegistry {
  public function add(Subscriber $s): void;                       // boot time only
  /** @return list<Subscriber> ordered by phase, then registration */
  public function for(string $eventClass): array;                 // matches by is_a, so marker interfaces work (D2)
}
interface IDeliveryLedger {
  public function delivered(string $subscriberId, string $eventId): bool;
  public function markDelivered(string $subscriberId, string $eventId): void;
  public function markFailed(string $subscriberId, string $eventId, string $error, int $attempt): void;
}
final class IntegrationDelivery {        // the portable drain bracket (today ddd-wordpress/integration-events.php:77-104)
  public function deliver(string $eventClass, array $wrapped): DeliveryOutcome {} // unwrap → Correlation::within(for_fact(event_id)) → each subscriber
}
namespace TangibleDDD\Runtime;
final class OrderedListenerDispatcher implements \TangibleDDD\Application\Events\IDomainEventDispatcher {} // local, sync, class + marker, Reactions frame
interface IRelayWakeup { public function poke(string $consumerPrefix): void; }          // after commit; null default
interface ISubscriberProbe { public function hasSubscribers(string $integrationAction): ?bool; } // null = unknown
```

- Delivery is at-least-once per subscriber. `deliver()` skips subscribers already in the ledger, runs the rest in phase order, each command in its own transaction, records success after the subscriber returns, and records a failure and continues with the next subscriber if one throws. It returns `DeliveryOutcome{delivered, failed}`. The delivery runner retries the fact while `failed` is non-empty, and each retry runs only the failed subscribers (C20, X8). A crash between a subscriber's commit and `markDelivered` re-runs that subscriber, so listeners stay idempotent, helped by deterministic command ids (3.8).
- `ITransport::submit` must return an acceptance or throw; a `0` AS action id throws (C 7.3). The publisher never adds a relative delay (bug 3).
- The local dispatcher propagates the first listener exception, which rolls back the command. It opens and closes the `Reactions` frame like `WordPressEventDispatcher.php:27,31`.
- sf maps: one `IntegrationFactMessage` per fact on `ddd_facts` (Doctrine transport, same DBAL connection, `use_notify`), handled by one handler calling `IntegrationDelivery::deliver`. wp: the legacy AS action per fact on the unchanged hook, with ddd-wp wrapping each DDD-registered callback into the invoker. pdo: a `deliver` job row per fact in `{prefix}_ddd_jobs`.

### 3.6 Job scheduling and wakeups

```php
namespace TangibleDDD\Runtime\Scheduling;
enum WakeKind: string { case Continue = 'continue'; case Timeout = 'timeout'; case ResumeRetry = 'resume_retry'; case Deliver = 'deliver'; }
final class WakeupIntent { public function __construct(
  public readonly WakeKind $kind, public readonly string $consumer, public readonly ?int $processId,
  public readonly ?int $stepIndex, public readonly ?string $expectedStatus, public readonly \DateTimeImmutable $dueAt,
  public readonly string $idempotencyKey /* e.g. "timeout:{process_id}:{step_index}" */) {} }
interface IWakeupScheduler {
  public function schedule(WakeupIntent $i): void;       // same tx as the process save; duplicate key = no-op
  public function cancel(string $idempotencyKey): void;
  /** @return list<ClaimedWakeup> */
  public function claimDue(\DateTimeImmutable $now, int $limit, int $leaseSeconds): array;
  public function complete(ClaimedWakeup $w): bool;      // fenced by claim token
  public function retryLater(ClaimedWakeup $w, string $error, \DateTimeImmutable $nextAt): bool;
}
namespace TangibleDDD\Runtime;
final class Drain {
  public function runOnce(int $maxItems = 200, int $maxSeconds = 50): DrainReport {} // relay batch, then due wakeups/jobs; returns
}
```

- `schedule` throws if called outside an active transaction on the process store's connection; the intent and the state change commit together or not at all (C8, C9, E F8). `due_at` is absolute UTC.
- Every wake handler is stale-safe: under the process lock it re-reads the row and no-ops unless `expectedStatus` and `stepIndex` still match. That generalises `ProcessRunner.php:348-353` to all kinds.
- `runOnce` is bounded by item count and wall time, safe to run concurrently from overlapping cron invocations (claims and leases), calls `RuntimeReset::betweenMessages()` in `finally` after each item, and never loops or sleeps. A host that wants a daemon writes `while (true) { $drain->runOnce(); sleep(1); }` itself.
- Implementations: pdo: rows in `{prefix}_ddd_jobs` executed by `runOnce`. wp: rows in an additive `{prefix}_ddd_wakeups` table, relayed to AS on the **legacy hooks with legacy args** (`{prefix}_process_continue(int)`, `{prefix}_await_timeout(int, int)`, `ddd-wordpress/hooks.php:168-181`), so a rollback to a 0.6 winner still drains them (B16). sf: rows in `ddd_wakeups` relayed to the `ddd_wakeups` Messenger transport with no multi-day `DelayStamp` (E section 7). mem: `InMemoryWakeupScheduler` driven by `FrozenClock`.

### 3.7 Locking

```php
namespace TangibleDDD\Runtime\Lock;
final class LockKey { public function __construct(public readonly string $consumer, public readonly string $tenant /* blog id or '' */, public readonly int $processId) {} }
final class LockNotAcquired extends \RuntimeException {}     // timeout AND error; retryable
interface IProcessLock {
  public function acquire(LockKey $k, float $timeoutSeconds): LockHandle;   // throws LockNotAcquired
  public function release(LockHandle $h): void;                              // never throws; a failed release is logged as a bug
  public function heldCount(): int;                                          // for the worker-reset guard
}
final class ReentrantProcessLock implements IProcessLock {}  // core wrapper: counts per key, so adapters need not be re-entrant
```

- Only a definite success enters. MySQL `GET_LOCK` returning `0`, `NULL`, or a query error, and Postgres `pg_try_advisory_lock` returning false or erroring all throw `LockNotAcquired` (bug 1).
- On `LockNotAcquired` the runner schedules a `ResumeRetry` / re-queues the delivery with backoff; the wake is never dropped (C2).
- Lifetime: held for exactly one wake. Acquire, re-read the row, act, release in `finally`. Never held across a message or `runOnce` item boundary; the reset guard asserts `heldCount() === 0`.
- Names: MySQL (wp, pdo) `ddd:` + sha1(`consumer|tenant|process_id`) truncated to 64 characters (the GET_LOCK limit). wp **also** takes the legacy name `ddd_process_<id>` during the compatibility window, always in the same order after the new name, so mixed 0.6/0.7 requests on one site still exclude each other (B21, C3). Postgres: see 5.2.
- Fencing: every `IProcessStore::save` checks the row `version` (3.8), so a holder whose session lock vanished (reconnect) cannot overwrite a newer state.

### 3.8 Process store, ignition, effects, codec

```php
namespace TangibleDDD\Runtime\Process;
enum IgnitionResult { case Inserted; case AlreadyIgnited; }
interface IProcessStore {
  public function insertIgnited(LongProcess $p, string $processClass, string $eventId): IgnitionResult; // unique-constraint gate
  public function insert(LongProcess $p): int;                         // manual start
  public function find(int $id): ?LongProcess;                         // call under the lock
  public function save(LongProcess $p, int $expectedVersion): int;     // new version; throws ConcurrentProcessModification on 0 rows
  /** @return list<int> */
  public function findWaitingFor(string $eventClass, ?string $awaitKey = null): array; // indexed (process_waits), ids only (E F14)
  /** @return list<StrandedProcess> */
  public function findStranded(\DateTimeImmutable $now): array;        // running/scheduled with no live intent past threshold
}
final class ProcessStoreFailed extends \RuntimeException {}           // insert/update failure; never set_id(0) (C27, C R12)
final class QuarantinedProcess extends \RuntimeException {}           // unknown stored class → row status 'quarantined' (E F12)

namespace TangibleDDD\Runtime\Effects;             // D1
interface IExternalEffectCommand extends \TangibleDDD\Application\Commands\ICommand {
  public function idempotencyKey(): string;
  public function perform(): EffectResult;          // outside the tx
  public function record(EffectResult $r): void;    // inside the tx (Transaction middleware)
  public function failureCommand(\Throwable $last): ?\TangibleDDD\Application\Commands\ICommand;
}
interface IEffectJournal { public function find(string $key): ?EffectResult; public function store(string $key, EffectResult $r): void; }

namespace TangibleDDD\Runtime\Codec;               // D6
final class LargeString { /* base64-wrapped scalar with declared max bytes */ }
final class PayloadTooLarge extends \DomainException {}   // thrown at append, before commit
```

- Ignition: `insertIgnited` returns `AlreadyIgnited` on a duplicate key (MySQL 1062, Postgres 23505) and the runner returns without running a step (bug 2). Manual starts carry a NULL event id and are never deduped.
- Ordering (D3, C10, E F2): a step's await is persisted with the checkpoint and its wakeup intents **before** the step's commands dispatch; a `IPrecheckAwait::already_satisfied()` hook absorbs a fact that committed before suspension.
- Resume and continuation re-read under the lock (C6, C7, E F1).
- Deterministic command id inside a fact cause: `uuid5(event_id, subscriber_id)`; inside a process step: `uuid5(process_id, step_index)`. `command_id` stays random outside those causes (E F3). The D1 journal keys on the declared `idempotencyKey()`; command id is for tracing only.
- Legacy bridge: `LegacyProcessStore(IProcessRepository)` for consumer implementations (cred) runs ignition under a named lock (the hotfix approach) and saves without version fencing, with a logged warning.

### 3.9 Actor, audit, trace/correlation, signals, observers

```php
namespace TangibleDDD\Runtime\Audit;
enum ActorKind: string { case User = 'user'; case Cli = 'cli'; case System = 'system'; case Machine = 'machine'; }
final class Actor { public function __construct(public readonly ActorKind $kind, public readonly ?string $id, public readonly ?string $label = null) {} }
interface IActorProvider { public function current(): Actor; }                 // never throws; default Cli/System by SAPI
interface IAuditPolicy { public function audits(object $command): bool; public function captureParameters(object $command): bool; }
interface IAuditSink { public function open(AuditOpen $r): void; public function close(AuditClose $r): void; } // may throw
final class NullAuditSink implements IAuditSink {}
interface IEnvironmentProvider { public function describe(): array; }          // wp: blog_id, WP version; sf: env name
// Redactor gains: __construct(array $extraKeys = [], ?\Closure $isSensitive = null); #[Sensitive] and #[NotAudited] property attributes (D8)
// #[Audit(false)] command attribute read by the default policy (D12)

namespace TangibleDDD\Application\Correlation;   // existing statics, plus:
final class FactRef { public function __construct(public readonly string $eventId, public readonly string $eventClass, public readonly string $correlationId) {} }
// Correlation::current_fact(): ?FactRef   — the fact being delivered, when the ambient cause is Kind::Fact (D13)

namespace TangibleDDD\Runtime;
interface IInfrastructureSignalDispatcher { public function emit(\TangibleDDD\Application\Infrastructure\IInfrastructureEvent $e, IConsumerIdentity $c): void; }
interface IFactObserver { public function observe(\TangibleDDD\Domain\Events\IIntegrationEvent $e, OutboxRecord $r): void; }
final class RuntimeReset { public static function betweenMessages(): void {} } // Correlation + Reactions + UoW + runner transients; asserts no leaks
```

- Audit: the nesting guard (`CommandDispatchedInsideCommand`) is unconditional and runs before the policy or sink (`CorrelationMiddleware.php:41-47`). A sink failure after the domain commit is caught, logged and emitted as a signal; the business outcome stays committed (spec scenario "Audit writer fails after domain commit").
- Signals are never silent: the core default logs through PSR-3 (today they vanish outside WP, `InfrastructureEvent.php:46-53`). wp fires both legacy hook names.
- Observers never break publication; errors are caught and logged (A F-07).
- Trace: `Correlation`/`TraceContext` are unchanged. The static state is cleared only by `RuntimeReset`, which first asserts `Correlation::peek() === null` (a leak is a bracket bug and fails loudly; E section 8).

### 3.10 Operator view (D9)

```php
namespace TangibleDDD\Runtime\Ops;
enum Layer: string { case Relay = 'relay'; case Delivery = 'delivery'; case Wakeup = 'wakeup'; case Process = 'process'; case Workflow = 'workflow'; case Transport = 'transport'; }
final class OperatorItem { /* layer, consumer, key (event_id|subscriber|process_id), attempts, budget, last_error, first_seen, repair_actions */ }
interface IOperatorView { /** @return list<OperatorItem> */ public function list(?Layer $layer = null, int $limit = 100): array; }
```

- Read-only; each adapter merges its layers. Repair commands (retry, replay, discard, resume stranded, fail stranded) are `ITransactionalCommand`s with status and lease guards (C23).

### 3.11 Port → D demand map

| D | Library port(s) | Owner | In library? |
|---|---|---|---|
| D1 ExternalEffect | `IExternalEffectCommand`, `IEffectJournal`, `EffectMiddleware` (between Correlation and Transaction) | core + sf journal table | yes |
| D2 marker subscription | `ISubscriptionRegistry::for()` by `is_a`; `OrderedListenerDispatcher` | core + sf compile-time map | yes |
| D3 keyed await | await keys, `AwaitAny`, dynamic `AwaitAll`, precheck, persist-before-dispatch, `findWaitingFor(class, key)` | core + sf `process_waits` | yes |
| D4 lock scope | `IProcessLock` + section 5.2 | core contract + sf | yes |
| D5 actors | `IActorProvider`, `ActorKind::Machine` | core + sf providers; TXP authenticators set the actor | yes |
| D6 large strings | `LargeString`, cap at append, quarantine | core + sf column types | yes |
| D7 durable alarms | `IWakeupScheduler` with absolute `due_at` | core + sf | yes |
| D8 redaction | `Redactor` extension, `#[Sensitive]`, `#[NotAudited]` | core | yes |
| D9 operator view | `IOperatorView` | core port + sf merge (incl. Messenger failure transport) | yes; TXP's unacked-job layer is TXP's |
| D10 workflow ignition | ignition ledger with caller-supplied dedup key (`#[StartsOn]` for workflows) | core + sf | yes |
| D11 return values | already works; receipt-rule docblock relaxed | core | yes (docs) |
| D12 audit policy | `IAuditPolicy`, `#[Audit(false)]` | core | yes |
| D13 cause + uuid5 | `Correlation::current_fact()`, `Uuid::v5`, deterministic ids | core | yes |
| D14 post-commit wakeup | `IRelayWakeup` + sf transactional `NOTIFY` / `LISTEN` relay with poll fallback | core no-op + sf | yes |

All fourteen are generic and live in the library (E section 9; this answers module-map Gate 1 decision 10). TXP-local code implements only its domain effects, authenticators and the unacked-job layer.

---

## 4. Shared conformance scenarios

The suite lives in `packages/ddd-conformance/` as abstract PHPUnit cases; each adapter extends them with a fixture factory. Names are stable identifiers, used in test method names and CI output. Host columns: **mem** (in-memory doubles, same process), **pdo** (MySQL 8, separate `php` processes where marked), **wp** (WP 7.1.2 + MySQL 8, AS runner), **sf** (Postgres 16, Messenger). "x" = must pass; "-" = not applicable.

| Id | Scenario | Expected result | mem | pdo | wp | sf |
|---|---|---|---|---|---|---|
| `cmd.commit-atomic` | handler writes domain row, reaction stages a fact, commit | domain row and outbox row both present | x | x | x | x |
| `cmd.commit-failure` | inject failure on COMMIT | no domain row, no outbox row, original exception surfaces, audit shows error | x | x | x | x |
| `cmd.reaction-throws` | in-tx reaction throws | full rollback; nothing relayed | x | x | x | x |
| `cmd.no-boundary` | `ITransactionalCommand` with no boundary | `NoTransactionBoundary` before the handler runs | x | x | x | x |
| `cmd.nested-rejected` | outer tx open, policy Reject | `NestedTransactionRejected`; outer tx untouched | x | x | x | x |
| `cmd.guards-without-audit` | nested command, post-seal event, re-raised fact, audit off | existing guards still throw | x | x | x | x |
| `cmd.return-value` | command returns a DTO through all middleware | value returned unchanged (D11) | x | x | x | x |
| `relay.fresh-process-pickup` | process A commits a fact and exits; process B (fresh `php`) runs `runOnce`/worker | B delivers it; no state shared except the DB | - | x | x | x |
| `relay.crash-after-commit` | commit, kill before any relay | fact still pending; next run delivers once | - | x | x | x |
| `relay.crash-after-submit` | transport accepted, crash before `accept` | shared-connection transports: both roll back, one delivery; others: same `event_id` may recur, subscriber effect idempotent | x | x | x | x |
| `relay.lease-fencing` | A claims, lease expires, B claims and accepts, A finishes late | A's `accept`/`retryLater` affect 0 rows | x | x | x | x |
| `relay.invalid-acceptance` | transport throws or returns no ref | retry per relay budget, then DLQ; never marked accepted | x | x | x | x |
| `relay.pause-holders` | two holders overlap, one released, one expires | remaining hold still pauses; expiry honoured | x | x | x | x |
| `relay.replay-keeps-identity` | replay a DLQ row whose fact ignites a process | same `event_id`; no second process | x | x | x | x |
| `delivery.double-delivery` | the same fact delivered twice | each subscriber's effect applied once (ledger + idempotent handler); ignition once; resume once | x | x | x | x |
| `delivery.subscriber-isolation` | listener A ok, listener B throws once | ignition and resume still run; retry runs only B | x | x | x | x |
| `delivery.phase-order` | one fact starts process B and is awaited by suspended A | B ignited first; order listener → ignition → resume | x | x | x | x |
| `delivery.delayed-once` | fact with `delay() = D` published at t0 | due at t0 + D ± tick, delivered once; a retry adds no delay; a legacy row with `delay_seconds > 0` and past `scheduled_at` is enqueued immediately | x | x | x | x |
| `lock.contention` | connection 2 holds the process lock; a wake arrives | wake waits up to the timeout, then `LockNotAcquired`; row unchanged; wake re-queued and later succeeds | x | x | x | x |
| `lock.acquire-error` | lock backend returns NULL / false / error | `LockNotAcquired`; critical section never entered; no save | x | x | x | x |
| `lock.reentrant-balance` | timeout path acquires, then `with_process` acquires again | balanced; `heldCount() === 0` at the end | x | x | x | x |
| `lock.namespace` | two consumers, same process id | no cross-blocking | - | x | x | x |
| `process.ignition-race` | two workers deliver the same igniting fact concurrently | exactly one process row; the loser runs no step | x | x | x | x |
| `process.await-all-concurrent` | two keys of a 2-key AwaitAll delivered concurrently | resumes once with both keys | - | x | x | x |
| `process.timeout-vs-event` | timeout and awaited fact race | exactly one of resume or timeout applies; no resurrection after compensation | x | x | x | x |
| `process.await-before-dispatch` | awaited fact delivered synchronously inside the step's dispatch | process still resumes | x | x | x | x |
| `process.intent-survives-queue-failure` | process save succeeds, transport unavailable | intent row remains; a later run wakes the process | x | x | x | x |
| `process.stale-wakeup` | continuation for a step already passed | no-op | x | x | x | x |
| `process.crash-mid-step` | kill after the step's command commits, before checkpoint | stranded item in operator view; resume re-runs the step with the same deterministic command id | - | x | x | x |
| `process.fresh-process-resume` | P1 suspends; P2 (fresh) delivers the awaited fact; P3 (fresh) fires the stale timeout | completes once; timeout no-op (C section 8) | - | x | x | x |
| `process.alarm-long` | 25 h alarm, worker restarted, fake clock advanced | fires once | x | x | x | x |
| `worker.no-leak` | two messages in one worker, first fails | second sees `Correlation::peek() === null`, empty UoW, null resume argument, zero held locks | x | x | x | x |
| `audit.sink-fails` | audit sink throws after domain commit | business result committed; signal emitted | x | x | x | x |
| `codec.large-payload` | 1 MB binary string field | round-trips via `LargeString` or fails before commit with `PayloadTooLarge` | x | x | x | x |
| `decode.unknown-class` | stored process class no longer exists | row quarantined with reason; worker continues | x | x | x | x |
| `effect.journal-reuse` (D1) | `perform` ok, `record` throws, redelivery | `perform` not called again; `record` runs with the journaled result; budget exhaustion commits the failure command once | x | x | - | x |
| `wakeup.post-commit` (D14) | commit, NOTIFY suppressed, rollback | delivered < 1 s with NOTIFY; within the poll interval without; nothing on rollback | - | - | - | x |

wp runs D1 only when a WP consumer needs it (O9). D14 is sf-only because only Postgres has transactional NOTIFY; other hosts rely on polling, which `relay.fresh-process-pickup` already covers.

---

## 5. Retry budgets, Postgres lock, recoverable state

### 5.1 Retry budget split and the one failure view

| Layer | Owner | What it retries | Default budget | Exhaustion goes to |
|---|---|---|---|---|
| Relay submission | `OutboxProcessor` (core) | `ITransport::submit` failures only | `OutboxConfig::max_attempts` = 5, backoff 60 s × 2^n capped at 3600 s (`OutboxConfig.php:13-18`) | relay DLQ (`{prefix}_integration_dlq` / `ddd_dlq`), layer `relay` |
| Handler execution | delivery runner: wp ledger + `{prefix}_ddd_redeliver` AS hook (new name, B16); pdo `{prefix}_ddd_jobs.attempts`; sf Messenger `retry_strategy` on `ddd_facts` | failed subscribers of one fact only | 5 attempts, backoff 30 s × 2^n capped at 3600 s, per (event_id, subscriber_id) | ledger status `failed` (+ sf failure transport `ddd_failed`), layer `delivery` |
| Wake execution | `IWakeupScheduler` retry | `LockNotAcquired`, transient DB errors | 10 attempts, backoff 2 s × 2^n capped at 300 s | stranded process, layer `wakeup` |
| Step failure | runner compensation | none (a step failure compensates, as today) | 0 | process `failed`, layer `process` |
| Workflow item | `WorkflowHandler::$max_retries` | per item | 3 (unchanged) | layer `workflow` |

Rules: the budgets never multiply. The relay cannot see handler failures, and a handler retry never re-submits the outbox row. With a shared-connection transport (AS on `$wpdb`, the pdo jobs table, the Doctrine transport on the domain DBAL connection) submission and `accept` commit together, so relay failures are DB errors only. D1's failure command fires when the **handler** budget is exhausted (sf: `WorkerMessageFailedEvent` with `!willRetry()`). One operator view (`IOperatorView`, 3.10) lists all layers with attempts against budget. It is exposed through the WP dashboard plus a new `wp ddd ops` command, sf `ddd:ops:list` / `ddd:ops:repair`, and for pdo `OperatorView::list()` returning arrays the host renders (no UI). This answers review finding 7.

### 5.2 Postgres lock choice and lifetime (answers review finding 9, module-map Gate 1 decision 12)

- **Choice: session-scoped `pg_try_advisory_lock(bigint)`**, polled every 50-200 ms (jittered) until a 5 s deadline, matching `GET_LOCK(…, 5)` (`ProcessRunner.php:397`). The key is `(crc32(consumer_prefix) << 32) | (process_id & 0xffffffff)`. A hash collision only adds serialization, because state is always re-read under the lock.
- **Lifetime:** one wake. Acquire, re-read, act (several independently committed step commands), release with `pg_advisory_unlock` in `finally`. Nested acquisition goes through `ReentrantProcessLock`, so Postgres sees one acquisition per wake. Held zero times at every message boundary (reset guard).
- **Why not transaction-scoped:** `pg_advisory_xact_lock` would be released at the first step command's commit, mid-wake (`ProcessRunner.php:619-628`). It would only work if a wake became one transaction, which contradicts the committed-steps-then-compensate model (`:516-586`).
- **Fencing:** process rows carry `version`; every save is `WHERE id = ? AND version = ?`. A holder whose connection reconnected under it fails its save with `ConcurrentProcessModification` and the wake retries.
- **Connection prerequisite:** workers and `ddd:relay` use a direct, non-pooled connection (also needed for `LISTEN`). The bundle warns at boot when the DSN host matches a known pooler pattern (`-pooler`, port 6432). Web requests may use a pooled connection because they never take process locks. Neon provides a direct endpoint, which TXP must configure for workers (O7).
- **Not used:** Symfony Lock `PostgreSqlStore` (no re-entrancy guarantee matching the nesting, and an extra dependency).
- Row locks for TXP invariants (staging cap, name registry) are ordinary `SELECT … FOR UPDATE` inside a command transaction and belong to TXP.

### 5.3 Making process state and wakeup intent recoverable

1. **One transaction per state change.** The runner wraps "save process (version-checked) + write/cancel wakeup intents + write await rows" in `ITransactionBoundary::run` on the process store's connection. Step commands still commit in their own transactions, before or after, as today.
2. **Await before dispatch.** Checkpoint, await and timeout intent commit first, then the step's commands dispatch (C10, E F2).
3. **Intent rows are the source of truth; the transport is a projection.** A relay step (in `runOnce`, the wp relay tick, or sf `ddd:relay`) moves due intents to the transport. Losing a transport message, or a crash between save and enqueue, leaves the intent due, and the next tick re-projects it (C8, C9). Post-commit wakeup (D14) only shortens latency.
4. **Stale-safe handlers.** Each wake re-reads under the lock and checks `expectedStatus` / `stepIndex`.
5. **Stranded scan.** `findStranded()` runs in every relay tick: `scheduled` rows with no live intent get a fresh `Continue` intent automatically (continuation is stale-safe); `running` rows whose lock is free and whose `updated_at` is older than a threshold (default 15 min) are reported in the operator view only, with `ResumeStrandedProcess` / `FailStrandedProcess` repairs, because an automatic re-run would repeat step effects.
6. **Ignition is gated by the unique constraint** inside the same transaction as the initial save (3.8).

---

## 6. The three bug fixes: frozen intended behaviour

| Bug | Code today | Intended behaviour (frozen) | Scenario | Hotfix 0.6.7 (prepared, unreleased) | Extraction branch |
|---|---|---|---|---|---|
| **1. GET_LOCK NULL runs unlocked** | `(string) $acquired === '0'` at `ProcessRunner.php:399`; NULL → `''` passes. Masked by `tests/wp-stubs.php:23` returning null and `AwaitTimeoutTest.php:23-25` | Only a definite acquisition enters the section. Timeout, NULL and query error all mean "not acquired": no step runs, no save, the exception propagates, and the wake is re-queued, not lost. Lock names are namespaced by consumer and tenant. | `lock.acquire-error`, `lock.contention`, `lock.namespace`, `lock.reentrant-balance` | `if ($acquired === null \|\| (string) $acquired !== '1') throw new LockingException(...)` with the error or timeout reason; the stub returns `'1'` for GET_LOCK/RELEASE_LOCK; the false comment at `:392,400` corrected. **Lock name unchanged.** | `IProcessLock` + `ReentrantProcessLock`; wp takes the new and the legacy names; re-queue via `ResumeRetry` intent |
| **2. Ignition check-then-insert** | `has_ignition` SELECT (`ProcessRepository.php:90-100`) then a separate insert (`ProcessRunner.php:166-175`); index `idx_ignition` not unique (`ddd-wordpress/tables.php:131`) | For a given `(process_class, event_id)` exactly one process row ever exists, however many deliveries, replays or workers. The loser returns quietly without running a step. Manual starts (no event id) are unaffected. | `process.ignition-race`, `relay.replay-keeps-identity`, `delivery.double-delivery` | Schema-free: after bug 1, a named lock `ddd_ign_` + md5(prefix\|class\|event_id) around re-check + insert (split `start()` into persist and run) | `insertIgnited` on a unique constraint (X7); replay keeps `event_id` |
| **3. Delayed events delayed twice** | `scheduled_at = now + delay` (`OutboxRepository.php:31`), fetch gated by `scheduled_at <= now` (`:97`), then the AS publisher adds `delay_seconds` again (`ActionSchedulerOutboxPublisher.php:21-27`), again on every retry. Masked by `tests/Fakes/FakeOutboxRepository.php:53-54` | The due time is absolute UTC on the row and honoured once. The publisher schedules at `max(now, scheduled_at)`; when that is not in the future it enqueues async. Retries are gated by `next_attempt_at` only. Rows written by older code (with `delay_seconds` and `scheduled_at` set) are not delayed again. `{prefix}_outbox_publish_external` hookers are documented not to add `delay_seconds`. | `delivery.delayed-once`, `process.alarm-long` | Same publisher change; changelog "behaviour fix: delays halve to their declared value". AS actions already queued keep the doubled delay. | `ITransport::submit(..., $dueAt)` with no relative delay anywhere |

Every fix lands test-first: a unit test that fails on `598858c`, then the fix. The hotfix branch is `hotfix/0.6.7` from tag `v0.6.6` and is never tagged in this project. Only cred ignites processes (`#[StartsOn]`) or emits delayed facts (D F8), so cred's `EndpointAuthRefresh` and `BehaviourWorkflowReschedule` delays shorten after bug 3 (O10).

---

## 7. Compatibility fixture matrix

### 7.1 Legacy versions under test (from report D)

| Fixture | Source | Why |
|---|---|---|
| **L-0.6.5-compiled** | shipped zips: `tangible-lms-0.12.0`, `tangible-quiz-0.7.0`, `tangible-certificates-0.3.1` (bundled ddd 0.6.5 + `var/container/CompiledContainer.php`) | fixed-arity constructor calls (D F5), the hardest constraint |
| **L-0.6.6-runtime** | `tangible-cred-latest.zip` (ddd 0.6.6, container compiled at runtime from YAML, 27 service ids) | service-id and autowiring changes |
| **L-0.6.4** | tangible-datastream `04418d5` (`^0.6.4`, uses `Tangible_DDD_Versions` API, `DDDCompilerPasses`, `SelfExecutingCommandMiddleware`) | registry API and DI pass |
| **L-0.6.2** | `git archive v0.6.2` (LMS/quiz manifest floor; the local `anything` site vendors 0.6.2) | oldest in-window copy |
| **L-0.6.7-hotfix** | `hotfix/0.6.7` branch head | hotfix coexistence with N |
| **L-0.2.5** (negative) | `git archive v0.2.5` | expected: unsupported; asserted as a clear failure, not a pass |
| Historical scaffold YAML | `class-ddd-command.php:407-605` from every tag v0.6.0..v0.6.6, plus cred's `includes/di/*.yaml` | compile matrix (B9) |

N = the extraction branch root distribution (0.7.0). P = tangible-ddd activated as a plugin.

### 7.2 Load-order cases

| Case | Setup | Pass condition |
|---|---|---|
| `load.new-alone` | N only | winner N; command → event → listener round trip; self-consumer `di()` built; `wp ddd` registered |
| `load.legacy-first` / `load.new-first` | each L-0.6.x fixture with N, both activation orders | winner N in both; exactly one initializer; no fatal through `plugins_loaded:30`; self-consume shim works; `ReflectionClass::getFileName()` of probed core, wp and moved classes under N's path; dashboard version = `winner()` |
| `load.compiled-containers` | L-0.6.5-compiled plugins + N winner | every service in each shipped `CompiledContainer` resolves |
| `load.preloaded-class` | legacy plugin touches a `TangibleDDD\` class at include time, then N | mixture detected and reported in diagnostics; never silent |
| `load.plugin-active` | P + any of the above | newest wins |
| `load.new-twice` | N + N (same version) in two plugins | one registration; listener fires once |
| `load.late` | N loaded after `plugins_loaded` | late-load branch behaves as `tangible-ddd.php:403-410` |
| `load.min-unmet` | a consumer requires a version above the winner | reported by `unmet_minimums()`; no fatal |
| `load.jetpack-mixed` | LMS (Jetpack Autoloader) + cred (plain Composer), different N builds | every `TangibleDDD\` class from one distribution path (D F13) |
| `load.v0-2-negative` | L-0.2.5 + N | fails early with a named unsupported-version message |

### 7.3 Pending-row fixtures (written by a legacy winner, drained by N, and the reverse)

- Outbox rows `pending`, `dlq` (with DLQ row), leased (`locked_until` in the future), delayed (`delay_seconds > 0`), `is_unique`; the pause option `{prefix}_outbox_pauses`.
- Pending AS actions: `{prefix}_integration_*` with wrapped envelopes, `{prefix}_process_continue(int)`, `{prefix}_await_timeout(int, int)`, and the recurring `{prefix}_outbox_process`.
- Processes: `running`, `scheduled`, `suspended` with `AwaitEvent` and `AwaitAll` serialized by 0.6.5, compensating, and pre-existing duplicate ignitions.
- Behaviour workflows with work items and meta rows; command audit rows.
- Rollback: N writes all of the above, the winner switches to L-0.6.6 and L-0.6.2, queued work drains and decodes; schema `installed > DDD_SCHEMA_VERSION` is tolerated (B18).

### 7.4 Supported and unsupported combinations

| Combination | Status |
|---|---|
| N alone; N + any L-0.6.2..0.6.7 in either order; N + P; N + N | **supported** |
| Legacy copies only (today's sites) | unchanged behaviour; the hotfix reaches a site only if it loads first or P is active (B1) |
| ddd-core + ddd-wp from different plugins or versions | **unsupported, refused**: ddd-wp checks its sibling core version by path and does not initialize on a mismatch (B D-3) |
| A WP plugin bundling ddd-core without `tangible/ddd` | unsupported: on WP, core is always the winner's core; documented |
| `tangible/ddd` + `tangible/ddd-core` in one vendor tree | impossible (`replace`) |
| `Defaults/Pdo` under WordPress | refused at ddd-wp boot (B24) |
| 0.1.x anywhere; the reporting 0.2 feature branch with any 0.6+ consumer | unsupported (redeclare fatal / removed classes) |
| MariaDB | not claimed |
| ddd-symfony workers on a pooled Postgres endpoint | unsupported; boot warning |

---

## 8. Implementation waves

Authors and the paths each owns. A path belongs to one author per wave; a change outside it goes through the owning author. `packaging` owns every `composer.json` in the repo.

| Author | Owned paths |
|---|---|
| core | `packages/ddd-core/src/**` except `Defaults/Pdo/**`; `packages/ddd-core/tests/Unit/**`; `examples/plain-php/**` |
| pdo-default | `packages/ddd-core/src/Defaults/Pdo/**`; `packages/ddd-core/schema/mysql8/**`; `packages/ddd-core/tests/Pdo/**`; `examples/plain-php-durable/**` |
| wp | `ddd-src/**` and `ddd-wordpress/**` until they are emptied (wave 1 fixes, wave 2 moves jointly with core); `packages/ddd-wp/{src,wordpress,tests}/**`; `tests/Unit/**`; `tests/Integration/**`; `tests/Fakes/**`; `tests/wp-stubs.php` |
| symfony | `packages/ddd-symfony/{src,schema,config,tests}/**`; `examples/symfony/**` |
| packaging | `composer.json`, `packages/*/composer.json`, `tangible-ddd.php`, `loader/**`, `compat/**`, `ddd-wordpress/self/index.php` (shim, once wp has moved the rest), `.gitattributes`, `.github/workflows/**`, `deptrac.yaml`, `phpstan*.neon`, `tests/{Compat,Loader,harness}/**`, `tests/Unit/Loader/**` |
| conformance | `packages/ddd-conformance/**` |

### Wave 1: fix and fence (no file moves)

- **wp:** the three bug fixes in place (`ddd-src/Application/Process/ProcessRunner.php`, `ddd-src/Infra/Persistence/{OutboxRepository,ProcessRepository}.php`, `ddd-src/Infra/Services/ActionSchedulerOutboxPublisher.php`, `tests/wp-stubs.php`, `tests/Fakes/FakeOutboxRepository.php`), each test-first; mirror onto `hotfix/0.6.7` (schema-free variants, section 6). `symfony/yaml` stays a packaging change (below).
- **core:** new files only: the port interfaces of section 3.1-3.5 and 3.9 (identity, clock, transaction, outbox store and administration, pauses, transport, subscription, ledger, delivery invoker, local dispatcher, actor, audit, signals, observer, `RuntimeReset`, `HostDefaults`, `Uuid::v5`), and their in-memory doubles under `packages/ddd-core/src/{Runtime,Testing}/`. **This freezes the outbox, relay and delivery contracts, which tenancy-reference needs.**
- **conformance:** `packages/ddd-conformance/` skeleton; `cmd.*`, `relay.lease-fencing`, `relay.crash-after-submit`, `delivery.*`, `lock.acquire-error`, `worker.no-leak` against mem.
- **packaging:** PHP floor 8.2 (manifest, plugin header, `LoaderIdentityTest`); `symfony/yaml` to `require` (F-5, also on the hotfix branch; O11); `packages/*/composer.json` skeletons; `.gitattributes`; CI on `extraction/**` (wp-unit 8.2/8.4, wp-integration on Docker MySQL 8, static); hermetic harness (`WP_TESTS_ABSPATH`, pinned datastream ref, fresh DB, table-existence assertion; F-10..F-12); loader baseline fixture `load.legacy-first` recorded against today's code.
- Acceptance:
  - `vendor/bin/phpunit` → 628 + new tests green; the new bug tests fail on `598858c` (`git stash`-style check in CI log).
  - `tests/harness/run.sh wp-integration` → green on MySQL 8.0 from an empty DB.
  - `cd packages/ddd-conformance && composer install && vendor/bin/phpunit --group mem` → green.
  - `git -C . diff --stat v0.6.6..hotfix/0.6.7` shows only the three fixes, the stub, the yaml require and tests.

### Wave 2: split (M1 + M2) and Symfony start

- **core + wp (joint, batched):** `git mv` the 131 keep files and the core halves of the splits to `packages/ddd-core/src/`; the 20 moves and WP halves to `packages/ddd-wp/{src,wordpress}/`; R2 subclasses (`TransactionMiddleware`, `IntegrationListener`); `IConsumerIdentity`; `HostDefaults` wiring at ddd-wp init; self-consumer registers in `ConsumerRegistry`; `self/services.yaml` lists handlers explicitly instead of the relative resource dir (B6); `ddd-wordpress/di/tactician.yaml` fixed to real classes and compile-tested (A F-28, B26). The root suite runs after every batch.
- **wp:** ABI freeze tests: procedural signature snapshots (B14), golden derived names (B15), historical YAML compile matrix (B9), each consumer's `IDDDConfig` implementation loads unchanged (D F4).
- **symfony:** bundle, service-locator passes, `DbalTransactionBoundary`, `DbalPostgresOutboxStore` (fenced claim via `UPDATE … WHERE id IN (SELECT … FOR UPDATE SKIP LOCKED) RETURNING`), relay (`ddd:relay`, submit + accept in one tx), `IntegrationFactMessage` + delivery handler, ledger table, `DddRuntimeReset`, D5 providers (session user, console operator), D2 compile-time subscription map, `schema/postgres`, `ddd:schema:dump`.
- **packaging:** root autoload to `packages/*/src` + `replace`; version-unique loader entry; `ddd-wordpress/self/index.php` shim; winner autoloader maps both package dirs + `compat/` + fall-through diagnostics; `tests/Compat/core-clean-install.sh`; deptrac rules; loader fixtures of 7.2 except `jetpack-mixed`.
- **conformance:** `relay.*` and `delivery.*` on wp and sf.
- Acceptance:
  - `vendor/bin/phpunit` (root) → green.
  - `tests/Compat/core-clean-install.sh` → dependency closure exactly `{tangible/ddd-core, league/tactician, psr/container, psr/log}`, no WP globals after autoload, `examples/plain-php/run.php` exits 0.
  - `cd packages/ddd-core && vendor/bin/phpunit` with a bootstrap that loads only Composer autoload → green (no `wp-stubs.php`).
  - `vendor/bin/deptrac analyse` → 0 violations.
  - `tests/harness/run.sh loader` → all 7.2 cases except `jetpack-mixed` green.
  - `cd packages/ddd-symfony && vendor/bin/phpunit -c phpunit.integration.xml` on `postgres:16` → `cmd.*`, `relay.*`, `delivery.*` green.
- **TXP tenancy-reference can start at the end of wave 2.** Its library scope: D11 (return values), D5 (session user + console operator actors), D2 (marker subscription, local and integration), the transaction boundary, Postgres outbox, relay to Messenger, envelope-unwrapping delivery, worker reset. D13's `current_fact()` ships in wave 1 and is usable but not required. No process, lock or alarm ports.

### Wave 3: durable contracts on all three hosts (M3; replaces M3a)

- **core:** section 3.6-3.8 ports (`IWakeupScheduler`, `IProcessLock`, `ReentrantProcessLock`, `IProcessStore`, legacy bridges); `ProcessRunner` refactor behind ports with re-read under the lock, await before dispatch, one-transaction state changes, `ResumeRetry` on contention, stranded scan; `Drain::runOnce`; `IOperatorView`.
- **wp:** schema v8 (additive): `{prefix}_ddd_wakeups`, `{prefix}_process_ignitions` (backfilled with `INSERT IGNORE`), `{prefix}_ddd_delivery_ledger`, `long_processes.version`, outbox `claim_token`, pause rows (reading the option until drained, C25); GET_LOCK adapter with both names; per-callback invoker wrapping; `{prefix}_ddd_redeliver` hook; `wp ddd relay --once`, `wp ddd ops`.
- **pdo-default:** `PdoConnection`, `PdoTransactionBoundary`, `PdoOutboxStore`, `PdoOutboxAdministration`, `PdoPauseStore`, `PdoProcessStore`, `PdoJobStore` (delivery + wakeups), `PdoDeliveryLedger`, `MySqlNamedLock`, `PdoOperatorView`, `SchemaCheck`, `DurableRuntime::compose()`; `schema/mysql8/*.sql`; `examples/plain-php-durable/{produce,drain}.php`.
- **symfony:** `PostgresAdvisoryProcessLock` (section 5.2), `DbalProcessStore` with `ON CONFLICT DO NOTHING RETURNING id` and `version`, `process_waits`, `ddd_wakeups` + transport, `IRelayWakeup` via transactional `NOTIFY` and `LISTEN`, pooler warning, `ddd:ops:*`.
- **conformance:** `lock.*`, `process.*`, `relay.fresh-process-pickup`, `relay.crash-after-commit`, `delivery.delayed-once` on pdo, wp, sf.
- Acceptance:
  - `tests/harness/run.sh core-pdo` → `packages/ddd-core: vendor/bin/phpunit --testsuite pdo` green on MySQL 8.0 and 8.4, then the two-process script: `php produce.php && php drain.php && php drain.php` ends with the process `completed` and the stale timeout a no-op.
  - `tests/harness/run.sh wp-integration` and `run.sh conformance-wp` → green.
  - `cd packages/ddd-symfony && vendor/bin/phpunit -c phpunit.integration.xml --group conformance` → green on Postgres 16, run **without** a per-test transaction wrapper (E section 5).
  - All scenarios in section 4 marked x for pdo/wp/sf pass except those assigned to wave 4.

### Wave 4: TXP process demands, compatibility closure, release preparation

- **core:** D1 (`IExternalEffectCommand`, `EffectMiddleware`, journal port), D3 (`AwaitAny`, keyed awaits, dynamic `AwaitAll`, precheck), D6 (`LargeString`, cap, quarantine), D7 long alarms, D8 redaction extension, D10 workflow ignition with a dedup key, D12 `IAuditPolicy`, D13 deterministic ids.
- **symfony:** DBAL effect journal and failure-command trigger, `process_waits` any-of, D9 merged view including the Messenger failure transport, the E section 10 reference scenario as a kernel test.
- **wp:** rollback fixtures (7.3), `load.jetpack-mixed`, compiled-container resolution for all three shipped zips.
- **pdo-default:** workflow and work-item stores (O8), operator repairs.
- **packaging:** splitsh workflow (`workflow_dispatch` only, no secrets), CHANGELOG, artifact check (`git archive HEAD | tar t` lists no tests, docs, tools, ddd-symfony or ddd-conformance).
- **conformance:** `effect.journal-reuse`, `wakeup.post-commit`, `process.alarm-long`, `codec.large-payload`, `decode.unknown-class` on every host marked x.
- Acceptance: full CI matrix green (section 2 legs); `tests/harness/run.sh compat` green for every case in 7.2 and 7.3; nothing tagged or pushed to a mirror.
- **TXP process-kernel starts at the end of wave 3** for D4 and D14 (lock and wakeup), and needs wave 4 for D1, D3, D7, D10 and D13's deterministic ids. Its in-scope library items: **D1, D3, D4, D7, D10, D13, D14**. Out of scope for both first slices: D6, D8, D9, D12 (gateway, runtime and identity slices).

Review finding 2 is honoured: symfony starts in wave 2 from the wave-1 contracts, in parallel with the WP split, and never waits on loader work.

---

## 9. Decisions still open

Each has a recommended default. Wave 1 proceeds on the default unless the operator overrides.

| # | Decision | Recommended default |
|---|---|---|
| O1 | Ship the version-unique loader entry (D-2) on the prepared 0.6.7 hotfix branch too? | Yes on the branch; it only matters if the hotfix is ever released, and it stops future 0.6.x copies deduping. It cannot fix copies already deployed. |
| O2 | Add a non-gating MariaDB 10.11 smoke leg for ddd-wp, given existing WP sites run MariaDB? | Yes, non-gating and labelled "not claimed"; it detects regressions against what 0.6 already runs without making a support claim. |
| O3 | Buffer reset timing in `DomainEventsPublishMiddleware` (reset at start only, `:15-18`, A F-17) | Also reset in `finally` on failure, after `CorrelationMiddleware` has read `published()` for the audit row. |
| O4 | Touches indexing (`IFactObserver`) outside WP | Not wired in sf or pdo (`NullFactObserver`); nobody in D1-D14 asks for it. |
| O5 | `RetryDeliveryCommand` on `accepted`/`completed` rows | Refuse unless `force: true`; refuse leased rows always. |
| O6 | `is_unique` cancel scope (C26) | Match on payload signature, as `IOutboxRepository.php:101-111` documents; never cancel leased rows. Changelog entry. |
| O7 | TXP Postgres connection topology | Direct (non-pooled) endpoint for `ddd:relay` and `messenger:consume`; pooled allowed for web. TXP confirms on its hosting. |
| O8 | Behaviour-workflow stores in `Defaults/Pdo` | Wave 4, after processes; D10 is Symfony-first. |
| O9 | D1 and D2 on WordPress | Not in this extraction; core contracts allow a later wp adapter (D2 would need boot-time expansion of markers to concrete hooks). |
| O10 | cred's delay values after bug 3 | Ship the fix; ask cred's owner whether `EndpointAuthRefresh` / `BehaviourWorkflowReschedule` were tuned to the doubled delay, before any release. |
| O11 | `symfony/yaml` to `require` on the hotfix branch | Yes; it is a latent fatal (the self-consumer silently disappears, F-5) with no behaviour change otherwise. |
| O12 | Packagist exposure of `dev-extraction/*` branches | Operator to pause the Packagist hook or rename WIP branches, and reserve `tangible/ddd-core`, `ddd-wp`, `ddd-symfony` deliberately before any push containing them. Until then, do not push new package directories. |
| O13 | Messenger `HandledStamp` skip on retry (E open question 1) | Spike in wave 2; the design does not depend on it (X8). If it works, the ledger remains as the cross-host guarantee. |
| O14 | Relax the SelfHandlingCommand receipt-rule docblock for D11 (`SelfHandlingCommand.php:45-48`) | Yes: return values are supported for plain and self-handling commands; downstream code must still not depend on side effects of reactions. |
| O15 | Rename `ddd-wordpress/` subdirectories to PSR-4 case (`cli/`, `self/`) | No in this extraction: classmap for `wordpress/cli` and `wordpress/self`, PSR-4 for `Admin/` (F-4). |
| O16 | Integration host for the framework WP suite | In-repo fixture consumer (`tests/Fakes/Acme`) for framework tests; datastream becomes a separate consumer-compat job pinned by SHA. |
| O17 | Multisite prefix capture in `Infra\Config::for_wordpress()` (`Config.php:21-24`) | Preserve as is for the self-consumer (behaviour change risk, no demand). |
| O18 | tangible-certificates and docker-factory in scope | certificates: yes, in the fixture set (shipped compiled container). docker-factory populater: unsupported, documented. |
| O19 | Reporting's `feature/tangible-ddd` branch | Out of the compatibility window; its owner re-targets it to 0.7 packages when resumed. |
| O20 | Tactician `^2.0-rc1` as a non-root dependency under `minimum-stability: stable` (A F-24) | Verify in wave 1's clean-install script. If it fails, document that roots add `"league/tactician": "^2.0-rc1"` (TXP does this); do not change Tactician. |

## Open questions

Questions that no default can settle and that need a person:

1. Who owns tangible-certificates and tangible-reporting, and do real client sites combine LMS, quiz, certificates and cred? The 7.2 fixtures should mirror at least one real site (D open question 2).
2. Is `tangible-ddd` activated as a plugin (P) on any production site? That is currently the only path by which a hotfix reliably wins (B open question 3).
3. Does any consumer build step (Strauss, Mozart, the Tangible roller) rename or prefix vendor packages? That would change the loader analysis per consumer (B open question 2).
4. Who approves the release of the 0.6.7 hotfix, and on what evidence (conformance on MySQL 8 plus the cred delay check, O10)?
