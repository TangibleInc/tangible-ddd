# tangible-ddd extraction: contract register

> **Revision 2026-10-01.** Applies every ruling in [coordinator-rulings-wave0.md](coordinator-rulings-wave0.md) (code-truth findings #50-#68, host-feasibility #70-#84). Main changes: X1, X3, X7 and X8 restated; every port in 3.2-3.8 plus its in-memory double moves to wave 1, with transitional WP-backed implementations in wave 2; scenarios split into process-free and process variants and scheduled per wave per host by exact id (section 4); new scenarios `process.manual-start-in-drain`, `process.start-from-web`, `workflow.fact-ignition-once`; wp keeps writing `completed` and quarantines through a nullable `quarantine_reason`; wp projects wakeup intents to Action Scheduler at schedule time; `lock.namespace` is `-` on wp; hotfix guarantees stated in section 6; `ConsumerHandle` and `IntegrationConformance` become splits (counts now 131 keep / 17 split / 18 move); sf gets workflow stores for D10; D1 gains `IEffectJournal::invalidate` and a ledger-counted budget; core ships `SubscriptionRegistrar` and `DurableRuntime::compose()` is frozen; ownership overlaps removed; MariaDB gets no test leg anywhere; O1, O2 and O12 ruled. The rulings file stays as the record; where the two ever differ, the rulings win.

> **Revision 2026-10-01 (waves 2-5).** Records what waves 2-5 ratified. Each wave's notes are binding over this file where they differ: [wave1-notes.md](wave1-notes.md), [wave2-notes.md](wave2-notes.md), [wave3-notes.md](wave3-notes.md), and [wave5-notes.md](wave5-notes.md), which also records wave 4 because wave 4 has no notes file of its own. In summary:
>
> - **Wave 2.** The `wave2-*-change-requests.md` files are accepted as written. Rulings: sfc-1/sf-3 (a lost lease on accept rolls back a shared submission); sfc-2 (`delivery.delayed-once` on transports with their own clock: due no earlier than requested and no later than `max(requested, submit time)`); sfc-3/sfc-4 (`process_batch(?int $limit)` with per-event outcomes); sfc-5 (`retry()` of a dead letter removes its DLQ entry on every host); CR-PK-5 (transitional allowances expire by the wave they name); O13 answered.
> - **Wave 3.** CR-W3C-1..6, CR-PDO-1..8, CR sfp-1..3 and WP8-1..11 are accepted. Rulings: a deferred start is legal inside a command (sf default `StartMode::Deferred`); D13 deterministic step command ids are frozen; `long_processes.start_path` joins the v8 columns; **CR-PDO-6 is a core rule** (an expired-lease re-claim counts a relay attempt, and the row is dead-lettered at claim when it reaches `max_attempts`); WP8-10 (core `ResumeStrandedProcess` / `FailStrandedProcess` repairs, surfaced by each host's operator commands). The TXP tenancy-reference demands L1-L8 were assigned to wave 4.
> - **Wave 4.** CR-W4CE-1..10, CR-W4P-1..7, CR-PDO4-1..6, CR-PDOC4-1, CR sf-a-1..7, sf-b-1..6, sf-conf4-1..4, CR-WPC4-1..6, CR-W4C4-1..6 and PK4-1..3 are ratified as merged (wave5-notes, "Wave 4"). This revision applies two of them to the text: **CR-W4C4-1** adds three D3 ids to section 4 (47 ids now) and to the section 8 wave-4 lists; **CR-WPC4-6** records the 7.3 rollback suite (section 7.3). Also from wave 4: by-reference integration actions on wp are N-only and drained before a rollback (CR-WPC4-2, section 3.6).
> - **Wave 5.** The house-style rename is merged at `1b5ffe3` ([naming/table.json](naming/table.json), [naming/naming.md](naming/naming.md)). Signatures and prose in this register still use the names that applied when it was written, and the table maps them (for example `insertIgnited` → `insert_ignited`, `Drain::runOnce` → `run_once`). Wave 5 closes the TXP process-kernel demands, and adds a coordinator decision for wp: a DDD-registered listener gets one attempt by default (0.6 parity) and opts in to the 5.1 handler budget. Its rulings are recorded in wave5-notes.

- Status: wave-0 output, 2026-10-01, revised the same day per the rulings. It is the single reference that wave 1-4 authors build against. A change to anything marked **frozen** needs a register edit before the code edit.
- Source: `/Users/titustc/tgbl/repos/tangible-ddd`, branch `extraction/ddd-packages`, pinned `598858c` (0.6.6). All `path:line` citations are against that tree.
- Inputs: the wave-0 reports [A core boundary](wave0/A-core-boundary.md), [B WordPress compatibility](wave0/B-wordpress-compat.md), [C durable execution](wave0/C-durable-execution.md), [D consumer inventory](wave0/D-consumer-inventory.md), [E Symfony host](wave0/E-symfony-host.md), [F packaging and harness](wave0/F-packaging-harness.md); the TXP working spec `txp/.docs/specs/tangible-ddd-extraction.md` (and its 2026-09-30 review, findings 1-9); TXP `module-map.md` (D1-D14, slice list); the binding [coordinator rulings](coordinator-rulings-wave0.md) on the code-truth and host-feasibility reviews.

## 0. Fixed operator decisions (not re-opened here)

1. No bundled MySQL runtime, worker daemon or migration framework in core. The host passes its own connection (the "`$db` conn somewhere"). Core ships ports, in-memory doubles, one thin PDO adapter set that takes that connection, plain schema SQL, and a `runOnce()` drain the host schedules from cron or end of request. This supersedes spec M3a and review finding 4.
2. MySQL 8 is tested. MariaDB is not a claimed target and gets no test leg, gating or otherwise. Postgres is tested through ddd-symfony only.
3. The three verified bugs are fixed on the extraction branch. A 0.6.x hotfix branch is prepared but not released.
4. Tactician stays. Existing WordPress consumers keep working during migration.
5. Nothing is published, tagged or deployed. No consumer dependency changes.

## 0.1 Where the reports disagreed, and what the code says

| # | Topic | Positions | Resolution (with evidence) |
|---|---|---|---|
| X1 | Compatibility window | B and F keep 0.2.x in scope because tangible-reporting pins `^0.2.5`. D says the window is 0.6.2-0.6.6. | **D.** Reporting master has no `TangibleDDD` references. Its `feature/tangible-ddd` branch wires `CorrelationContext` and `CommandAuditMiddleware`, both deleted in 0.4.0 (`50fcd29`). The window is `>=0.6.2 <0.7`, frozen at the 0.6.5 contract. 0.2.x is unsupported **by decision** (section 7). It does not fail on its own: the winner's prepended autoloader (`tangible-ddd.php:274-297`) returns without loading when the winning copy lacks a file, so a deleted class such as `CorrelationContext` falls through to the consumer's own vendored copy and the natural failure mode is a silent mixed load. `load.v0-2-negative` therefore relies on active detection: at winner boot, ddd-wp probes for 0.2-only FQCNs (e.g. `TangibleDDD\...\CorrelationContext`) and raises a named unsupported-version error when `WP_DEBUG` is on, logging it otherwise. This also closes review finding 1. |
| X2 | PHP floor | A: core could claim 8.1. D and F: 8.2. | **8.2 for every package.** The locked Symfony 7.4 graph already needs 8.2 (`composer.lock`, `php >=8.2` on symfony/config, dependency-injection, filesystem, var-exporter); PHPUnit 11 refuses to start on 8.1 (F section 1); no consumer runs 8.1 (D 3.2). Claiming 8.1 for core alone buys nothing and adds a CI leg. |
| X3 | Postgres process lock | C: portable contract is a row lease with fencing; session locks optional. E: session `pg_try_advisory_lock`, re-read under the lock. | **Session advisory lock plus version-fenced saves** (section 5.2). The wake spans several commits (`ProcessRunner.php:619-628`) and nests the lock (`ProcessRunner.php:345` then `:249` via `with_process`), so a transaction-scoped lock cannot cover it. Workers need a direct, non-pooled connection anyway for `LISTEN` (D14), so the session lock adds no new prerequisite. C's concern about a stale owner is real (a reconnect from `doctrine_ping_connection` silently drops a session lock), so every process save is fenced by a `version` column and the runner does a fenced version touch before each step dispatch. The fence protects the process row only; step effects are protected by idempotency (deterministic command ids plus the D1 journal), not by the lock. On sf, `ProcessRunner::start()` persists the process and writes a `Continue` intent in the caller's transaction, and the first step runs in a worker; an in-band first step is opt-in (`ddd.process.inband_start: true`) and requires the direct connection (5.2, scenario `process.start-from-web`). |
| X4 | Owner of the Symfony-DI compiler passes | A: three options. E: ddd-symfony, with a WP wrapper. F: an optional bridge namespace inside core. | **Core owns the FQCNs** `TangibleDDD\Infra\DependencyInjection\{DDDCompilerPasses,LongProcessCatalogPass}` as a fenced bridge layer; `symfony/dependency-injection` is `suggest` + `require-dev` in core. Both wp and symfony already need them (the consumer scaffold registers them, `class-ddd-command.php:340,354`, and datastream calls `DDDCompilerPasses` at `includes/di/index.php:52`, D section 4), and the one-owner rule forbids duplicates. Deptrac forbids any portable core layer from importing the bridge. |
| X5 | Package layout vs the legacy self-consume path | Spec and F: sources under `packages/`. B5: the winner's registered path must contain `ddd-wordpress/self/index.php`, because a legacy copy's `tangible_ddd_self_consume` requires it (`tangible-ddd.php:372`) and a missing file is an uncatchable fatal. | **Both.** Sources move to `packages/*`. The root distribution keeps a forwarding shim at `ddd-wordpress/self/index.php` for the whole compatibility window (section 1.1). |
| X6 | `IDDDConfig` owner | A: core owns it for the compatibility window. B, D, E: split identity out, keep `IDDDConfig` as the WP contract. | **Core owns the interface FQCN unchanged, and gains a parent** `IConsumerIdentity` (section 3.1). `IDDDConfig` is a pure interface (no WP calls, `Infra/IDDDConfig.php:11-70`), and 17 `ddd-src` files type-hint it, including constructors that shipped compiled containers call with fixed arity (D F5). Moving it to wp would make core depend on wp. Portable code types against `IConsumerIdentity`; the WP concretes `DDDConfig`, `Config` and `ConsumerTables` move to wp. |
| X7 | Ignition uniqueness | C: `UNIQUE (process_class, ignited_by_event_id)` on the process table, after deduping existing rows. B22: a new additive ignitions table. | **Replaced (ruling on code-truth #50).** Neither proposal works as written: `start()` inside a fact drain stamps the ambient fact id as `ignited_by_event_id`, so a unique constraint on that column would silently drop legitimate manual starts. Ignition dedup uses a **new nullable column `ignition_key`** on the process table, set **only** by the `#[StartsOn]` ignition path (`ProcessRunner.php:166-172`) to `uuid5(event_id, process_class)`, with `UNIQUE (process_class, ignition_key)` (NULLs never collide). Manual starts inside a drain keep `ignited_by_event_id` as today and are never deduped (scenario `process.manual-start-in-drain`). On wp the column is additive (R5); `insertIgnited` also checks `long_processes.ignited_by_event_id` for the same class inside the ignition lock, so upgrade → rollback → roll-forward cannot double-ignite (a 0.6 winner in between writes no `ignition_key`). The wp backfill sets `ignition_key` in id order only for rows that came from the ignition path (`source = 'event'`), and reports duplicates only among those rows; a colliding row keeps NULL, is reported, and is never deleted, so the migration cannot fail on it. pdo-default and symfony have the same column on fresh schemas. The core contract (`insertIgnited` returns `Inserted` or `AlreadyIgnited`) is identical on every host. |
| X8 | Per-subscriber delivery | C: fan out one AS action per subscriber, or catch per callback plus a ledger. E: rely on Messenger `HandledStamp` skipping, else one message per (fact, subscriber). | **One delivery per fact, run by a core invoker that walks subscribers in priority order with a per-(subscriber, event_id) delivery ledger** (section 5.1). Amended per #59: `Subscriber` carries the **numeric priority**, because today's `integration_action()` subscribers register at any caller-chosen priority (including after resume, e.g. 100); the three named phases are constants `Subscriber::LISTENER = 10`, `IGNITION = 50`, `RESUME = 99`, not an enum. This keeps the ignition-before-resume ordering (`ProcessRunner.php:95,180`, priorities 99 and 50; listeners 10), keeps legacy AS action shapes for rollback (B16), and does not depend on unverified Messenger retry behaviour. On wp only DDD-registered callbacks are isolated and ledgered; raw `add_action` callbacks on integration hooks are outside the guarantee and documented as such. The HandledStamp spike (E open question 1) becomes an optimisation, not a design input. |
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
│   │   │   ├── Domain/ Application/ Infra/     # the 131 "keep" files (incl. the two DI bridge passes) + core halves of the 17 splits, same FQCNs
│   │   │   ├── Runtime/             # new ports and portable runtime: clock, HostDefaults, SubscriptionRegistrar, delivery invoker, runOnce drain, RuntimeReset
│   │   │   ├── Testing/             # in-memory doubles + IntegrationConformance (existing)
│   │   │   └── Defaults/Pdo/        # the pdo-default adapter set (deptrac-fenced; portable layers never import it)
│   │   ├── schema/mysql8/*.sql      # plain CREATE TABLE IF NOT EXISTS; applied by the host
│   │   └── tests/{Unit,Pdo}/
│   ├── ddd-wp/                      # tangible/ddd-wp (a layering artifact; WP plugins keep requiring tangible/ddd)
│   │   ├── composer.json            # tangible/ddd-core (exact self.version), woocommerce/action-scheduler ^3.9,
│   │   │                            # symfony/dependency-injection ^7.4, symfony/config ^7.4, symfony/yaml ^7.4 (require, F-5), makinacorpus/query-builder ^1.6
│   │   ├── src/                     # the 18 moved FQCNs + WP halves of the splits, same FQCNs (TangibleDDD\...)
│   │   ├── wordpress/               # today's ddd-wordpress/: procedural files, Admin/, cli/, self/, di/, migrations
│   │   └── tests/{Unit,Integration}/
│   ├── ddd-symfony/                 # tangible/ddd-symfony (not in root autoload, not in replace, export-ignored)
│   │   ├── composer.json            # tangible/ddd-core, doctrine/dbal ^4, symfony/messenger ^7.4, symfony/framework-bundle ^7.4
│   │   ├── src/                     # TangibleDDD\Symfony\{Bundle,DependencyInjection,Messenger,Persistence,Lock,Console,Runtime}
│   │   ├── schema/postgres/*.sql
│   │   └── tests/{Kernel,Integration}/
│   └── ddd-conformance/             # tangible/ddd-conformance: dev-only, requires phpunit; shared scenario suite (section 4);
│                                    #   owns its own composer.json (section 8)
├── examples/{plain-php,plain-php-durable,symfony}/
├── tests/{Unit,Integration}/        # today's root suites, kept green through every move batch
├── tests/{Compat,Loader,harness}/   # clean-install, loader fixtures, Docker DB harness
└── .gitattributes                   # export-ignore tests/ tools/ docs/ examples/ packages/ddd-symfony/ packages/ddd-conformance/ packages/*/tests/
```

Namespaces: portable core keeps `TangibleDDD\` (D F6: every consumer names these FQCNs). New core runtime types go under `TangibleDDD\Runtime\`. The PDO default is `TangibleDDD\Defaults\Pdo\`. Symfony adapter types are `TangibleDDD\Symfony\`. New WP adapter types are `TangibleDDD\WordPress\Adapter\`. Composer supports one PSR-4 prefix mapped to several directories, and each file exists in exactly one of them.

### 1.2 Ownership by package

| Package | Owns | Must not contain |
|---|---|---|
| ddd-core | Domain, CQRS, eventing, correlation/trace, consumer registry, value types, process and workflow algorithms, every port in section 3 (except `IHostConnection`, below), in-memory doubles, `SubscriptionRegistrar`, the core form of `IntegrationConformance` (targets `IntegrationTranslator`), the DI bridge (X4) | any WP symbol, `$wpdb`, `global $`, Action Scheduler, `TangibleDDD\WordPress\*`, Symfony outside the bridge, `makinacorpus/*`, an autoload `files` entry other than `Domain/Shared/assert.php`, the version-negotiation registry (`Tangible_DDD_Versions`) or any loader, a connection factory |
| ddd-core `Defaults/Pdo` (owner tag pdo-default) | `IHostConnection`, adapters over a host-supplied connection, MySQL 8 schema SQL, `SchemaCheck`, `DurableRuntime::compose()`, `Drain::runOnce()` | a DSN parser, credential lookup, `new PDO`, a migrator, a loop, signal handling, a CLI binary |
| ddd-wp | the WP halves: wpdb repositories and ports, Action Scheduler publisher and scheduler, WP hook facades, the procedural `TangibleDDD\WordPress\*` API, admin dashboard, WP-CLI, self-consumer, migrations (`dbDelta` ledger), legacy FQCNs per rule R2 | any use of `Defaults/Pdo` (B24, D-7) |
| ddd-symfony | bundle, compiler passes that build service locators (E S2), DBAL ports (including the D10 workflow stores and ignition ledger), Postgres lock, Messenger publisher and handlers, NOTIFY wakeup, console (`ddd:relay`, `ddd:ops:*`, `ddd:schema:dump`), worker reset | its own copy of any core FQCN or the DI passes |
| ddd-conformance | abstract scenario cases (section 4), fixture-factory contracts, its own `composer.json` | adapter code; any host package's fixtures beyond the factory interface |
| tangible/ddd (root) | loader, version-unique files entry, self-consume shim, `compat/` alias map (section 1.4), the release artifact | source files of its own beyond those |

### 1.3 Class-ownership rules (frozen)

- **R1 One owner.** Every FQCN has exactly one owning package. Aliases and facades for moved names live in ddd-wp or the root `compat/` map, never as a duplicate PSR-4 declaration.
- **R2 Legacy constructors.** The 0.6.5 constructor of every class in the ABI freeze list (B section 5, D section 4) stays callable exactly as the shipped compiled containers call it (LMS 0.12.0, quiz 0.7.0, certificates 0.3.1: `CompiledContainer.php:384-528`, e.g. `new TransactionMiddleware()`, `new ProcessRunner(...)`). If the legacy constructor names only core types, core keeps the FQCN and may only append optional trailing parameters; a `null` argument resolves from `TangibleDDD\Runtime\HostDefaults`, which ddd-wp populates at init and which is empty elsewhere. If it names a WP type or has a WP side effect, ddd-wp owns the legacy FQCN as a thin subclass of a new core class.
- **R3 Interfaces consumers implement never gain abstract methods** (`IDDDConfig`, `IOutboxRepository`, `IProcessRepository`, `IBehaviourWorkflowRepository`, `IOutboxPublisher`, `ICommandHandler`, `IQueryHandler`, `IAwaitMechanism`, ...). New capability goes on new interfaces (`IOutboxStore`, `IProcessStore`), and core wraps a legacy implementation in a degraded adapter where needed. LMS ships its own Doctrine `IOutboxRepository` (`lms:src/Infrastructure/Persistence/Doctrine/OutboxRepository.php:35`) and cred its own `IProcessRepository`.
- **R4 Persisted and subscribed strings do not change**: `DomainEvent::action()`, `integration_action()`, hook names, AS groups, option names, table names and columns, stored status values (wp keeps writing outbox `completed`, 3.4), AS arg shapes, envelope keys `__correlation_id/__sequence/__event_id` (B15-B17, A F-12). AS args are **associative** arrays that Action Scheduler passes as PHP named arguments, so their key names are frozen too: `{prefix}_process_continue` takes `['process_id' => int]`, `{prefix}_await_timeout` takes `['process_id' => int, 'step_index' => int]` (`ddd-wordpress/hooks.php:168-181`, `ProcessRunner.php:649-665`).
- **R5 Schema changes are additive**: new tables or nullable/defaulted columns only, **without exception** (no ENUM extension: quarantine is a nullable `quarantine_reason` column with status `failed`, and outbox rows keep `completed`), one ledger (`DDD_SCHEMA_VERSION`, `ddd-wordpress/migrations.php:39`) shared with the hotfix line; the 0.6.7 hotfix changes no schema (B20).

`HostDefaults` is process-static and boot-time only; `RuntimeReset` never clears it (A F-18).

### 1.4 Disposition of the 17 split classes

| Legacy FQCN | Owner after split | Core form | WP form |
|---|---|---|---|
| `Application\Persistence\TransactionMiddleware` | **wp** (ctor `?wpdb`) | new `Application\Persistence\TransactionalCommandMiddleware(ITransactionBoundary)` | `TransactionMiddleware(?wpdb $wpdb = null)` extends it with `WpdbTransactionBoundary`. Legacy behaviour the R2 subclass tests pin: outside WP, `new TransactionMiddleware()` throws a `TypeError` in the constructor (the typed `wpdb` property rejects a null `$GLOBALS['wpdb']`), so the `:34` fallback never runs; inside WP, `START TRANSACTION`/`COMMIT` results are unchecked (`:39,43`) |
| `Application\EventHandlers\IntegrationListener` | **wp** (ctor side effect) | new abstract `Application\EventHandlers\IntegrationTranslator` with the same protected `get_event_class()` / `get_command()` plus public final `event_class()` / `translate()` | `IntegrationListener extends IntegrationTranslator`, constructor still calls `integration_listener()` |
| `Application\Correlation\CorrelationMiddleware` | core | same ctor + optional `?IAuditSink, ?IActorProvider, ?IAuditPolicy, ?IEnvironmentProvider` | `WpdbAuditSink`, `WpActorProvider`, `WpEnvironmentProvider` registered in HostDefaults |
| `Application\Process\ProcessRunner` | core | same ctor + optional `?IProcessLock, ?IProcessStore, ?IWakeupScheduler, ?ISubscriptionRegistry, ?ITransactionBoundary, ?IClock` | GET_LOCK lock, AS wakeup relay, `add_action` subscription registrar (transitional forms in wave 2, final forms in wave 3) |
| `Infra\Services\OutboxProcessor` | core | same ctor + optional `?ISubscriberProbe, ?LoggerInterface, ?IClock`; `process_batch()` is the relay step of `runOnce`; the core logger uses `json_encode` through PSR-3 | `has_action` probe (`:69`), `error_log` logger, the `WP_DEBUG` check (`:159`) and the unguarded `wp_json_encode` on the failure/DLQ logging path (`:164`, fatal outside WP today) |
| `Infra\Services\OutboxIntegrationEventBus` | core | same ctor + optional `?IFactObserver` (errors caught) | touches indexer |
| `Application\Infrastructure\InfrastructureEvent` | core | `dispatch()` routes to `IInfrastructureSignalDispatcher` from HostDefaults; default logs via PSR-3, never silent | hook facade firing both `{prefix}_x` and `tangible_ddd_x` |
| `Application\Outbox\OutboxConfig` | core | unchanged value; `from_options()` delegates per X11; add `from_array()` | options reader |
| `Application\Commands\Command` | core | drop the `SelfConsumer\di()` override (`Command.php:24-26`); the self-consumer registers in `ConsumerRegistry` at init | self-consumer registration |
| 4 repair handlers | core | orchestration over `IOutboxAdministration` | `WpdbOutboxAdministration` |
| `Application\BehaviourWorkflows\WorkflowHandler` | core | drop `is_multisite` stamping (`:209`) and `error_log` (`:127`) | repositories stamp `blog_id` |
| `Infra\IDDDConfig` | core (interface) | `extends IConsumerIdentity` (X6) | `DDDConfig`, `Config` (moved) |
| `Infra\Consumers\ConsumerHandle` | core | stores `IConsumerIdentity` (was `IDDDConfig`, `ConsumerHandle.php:24`); adds `identity(): IConsumerIdentity`; `config(): IDDDConfig` returns the identity when it is an `IDDDConfig` and otherwise throws `NotAWordPressConsumer`; `matches_registration()` (`:90`) compares identities. Affected core call site: `ConsumerRegistry::config_for()` (`ConsumerRegistry.php:30,59`) keeps its `IDDDConfig` return type through `config()`; portable callers use `identity()` | WP call sites keep calling `config()`, which is safe because every WP consumer registers an `IDDDConfig`: `ddd-wordpress/modules.php:130,158`, `Admin/Dashboard/ConsumerCatalog.php:58,96`, `Admin/Dashboard/Query/TraceFragmentReader.php:35` |
| `Testing\IntegrationConformance` | core | targets `IntegrationTranslator` (no import of `IntegrationListener`, so core never depends on a wp FQCN) | a wp subclass keeps the `IntegrationListener` constructor-side-effect check |

Counts after the rulings: **131 keep** (including the two DI bridge passes, X4) / **17 split** / **18 move** = the 166 `ddd-src` files.

The 18 whole-file moves to wp keep their FQCNs (A section 3.8): `TangibleFieldsRenderer`, `WordPressActionHandler`, `WPErrorException`, `ConsumerTables`, `Config`, `DDDConfig`, the four wpdb repositories, `WordPressRepository`, `ISelect`, `QueryBuilderSelect`, `ISearchableRepository`, `RepositorySearchResult`, `ActionSchedulerOutboxPublisher`, `RoutingOutboxPublisher`, `WordPressEventDispatcher`. The two DI passes stay in core (X4), and `ConsumerHandle` and `IntegrationConformance` change from keep to split, which changes A's table by four rows. **File-level move map: [A section 3](wave0/A-core-boundary.md) is authoritative for all 166 `ddd-src` files, with the X4, R2 and split amendments above.**

### 1.5 Autoload and release artifact

- ddd-core autoload: PSR-4 `TangibleDDD\` → `src/`; files: `src/Domain/Shared/assert.php` only. `require vendor/autoload.php` defines no WP function, no `Tangible_DDD_Versions`, no `TANGIBLE_DDD_VERSION`, and adds no autoloader beyond Composer's (A F-01, F-3).
- Root `tangible/ddd` artifact (the only thing WP plugins install): root files plus `packages/ddd-core/` and `packages/ddd-wp/` from the same commit (the matched pair, B D-3, D F13), `loader/`, the shim. The winner's prepended autoloader maps `TangibleDDD\` to its own `packages/ddd-core/src` and `packages/ddd-wp/src`, consults the `compat/` alias map, and logs every fall-through to another copy as a diagnostic (B7, F-13). It does not throw, because `class_exists` probes run through it (`ddd-wordpress/hooks.php:126`).
- The files entry is version-unique per release so Composer's cross-vendor dedup (`vendor/composer/autoload_real.php:37-45`) cannot suppress a newer copy (B1). `LoaderIdentityTest` asserts the entry filename, the constant, the register literal, the function slugs and every `packages/*/composer.json` version agree.
- Split mirrors (`ddd-core`, `ddd-symfony`; `ddd-wp` only as a layering artifact) come from `splitsh-lite --prefix=packages/<name>`, one version line for all packages (0.7.0). The workflow is written in wave 4 with `workflow_dispatch` only and no secrets. Nothing runs until the operator approves publishing.
- TXP consumes before publishing through Composer path repositories to `packages/ddd-core` and `packages/ddd-symfony`, with `symlink: false` in package CI.
- Packagist indexes only the root `composer.json` (name `tangible/ddd`), so `dev-extraction/ddd-packages` is visible as a `tangible/ddd` branch version (D F12) but the new `packages/*` names are not registered by pushing. WIP branch versions `dev-extraction/*` are acceptable; keep pushing (O12, ruled).

---

## 2. PHP floor and database support matrix

| Package | PHP | CI legs | Database | How tested |
|---|---|---|---|---|
| ddd-core (portable) | >=8.2 | 8.2, 8.4 | none | unit suite without `wp-stubs.php`; clean-install script |
| ddd-core `Defaults/Pdo` | >=8.2, `ext-pdo` + `ext-pdo_mysql` (host) | 8.2 | **MySQL 8.0** (gating), MySQL 8.4 (optional, non-gating); InnoDB, utf8mb4, DYNAMIC rows | `tests/Pdo` + two-process drain script on Docker `mysql:8.0` (and `mysql:8.4` when the optional leg runs); the conformance suite runs a second time with `PDO::ATTR_EMULATE_PREPARES = true` |
| ddd-wp | >=8.2 | 8.2, 8.4 | **MySQL 8.0 via wpdb** (gating), 8.4 optional, non-gating | legacy unit suite + WP 7.1.2 integration on Docker MySQL, fresh DB per run |
| ddd-symfony | >=8.2 (Symfony 7.4) | 8.2, 8.4 | **Postgres 16** (gating), 17 optional, non-gating; direct (non-pooled) connection for workers | kernel + integration suite on Docker `postgres:16` |
| tangible/ddd (root) | >=8.2 | 8.2, 8.4 | as ddd-wp | everything above that ships in the artifact, plus loader and compat fixtures |

Not claimed, and no test leg of any kind: **MariaDB** (any package; O2 rejected). Also not claimed: Postgres through `Defaults/Pdo`, any other PDO driver, PgBouncer transaction pooling or a Neon `-pooler` endpoint for ddd-symfony workers, PHP 8.1. The `Defaults/Pdo` README says "MySQL 8 tested; other drivers untested". ddd-wp adds no MySQL-8-only syntax to the WP path without a register entry.

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

- Clock: never throws. All durable times (`due_at`, `scheduled_at`, `lease_until`) are computed from `IClock` and stored and parsed as UTC explicitly (C bug 3 fix note). A fresh `php` process cannot share a `FrozenClock`, so core `Testing\` also ships a test-only `EnvOffsetClock implements IClock` that adds the `DDD_CLOCK_OFFSET` env var (an ISO 8601 duration or seconds) to the system time; `examples/plain-php-durable` and the harness use it to make a timeout due in the second drain. It is never wired by default.
- Service registry: `ConsumerRegistry` stays in core (`Infra/Consumers/ConsumerRegistry.php:22-251`), with `add()` widened to `IConsumerIdentity`. Widening alone is not enough, because the value is stored in `ConsumerHandle` as `IDDDConfig`; `ConsumerHandle` is therefore a split (1.4): it stores `IConsumerIdentity`, adds `identity()`, and `config()` returns `IDDDConfig` or throws `NotAWordPressConsumer`. It is boot-time, process-static, and never reset per message. Error: `NoConsumerOwnsClass` when `send()` or `Event::prefix()` runs for an unregistered namespace. Non-registry path: `$bus->handle($command)`. Core exports `CommandBusAware::COMMAND_BUS_ID` and `QueryBusAware::QUERY_BUS_ID = 'tactician.query_bus'` (A F-29). sf registers one consumer (`txp`) at `Bundle::boot()`; bounded contexts are namespaces, not consumers (E S8).
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
- Middleware: `TransactionalCommandMiddleware` wraps only `ITransactionalCommand`. Without a boundary it throws `NoTransactionBoundary` **before** the handler runs. (Today the `:34-36` "silent fallback" is dead code: outside WP the legacy constructor already fails with a `TypeError`, and inside WP query results are unchecked; see 1.4.) Order is frozen: Correlation → Transaction → DomainEventsPublish → SelfExecuting → handler (`ddd-wordpress/di/tactician.yaml:29-35`). Return values pass through unchanged (D11, A F-13).
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
  public function isDuplicateKey(\Throwable $e): bool;                     // driver error code MySQL 1062 / Postgres 23505 only
}
final class PdoConnection implements IHostConnection { public function __construct(\PDO $db) {} }

namespace TangibleDDD\Defaults\Pdo;   // frozen signature (ruling #80); acceptance fixture: examples/plain-php-durable
final class DurableRuntime {
  /** @param ContainerInterface|array<class-string, callable|object> $handlers
   *  @param list<class-string> $listeners  @param list<class-string<LongProcess>> $processes */
  public static function compose(IHostConnection $db, IConsumerIdentity $consumer, ContainerInterface|array $handlers,
                                 array $listeners, array $processes, ?IClock $clock = null): DurableRuntime {}
  public function bus(): CommandBus {}  public function drain(): Drain {}   // accessors: sketch, not frozen
}
```

- The host passes the connection its own repositories already use; that shared connection is the only thing atomicity needs (spec "Ports and standalone defaults"; B24). A CodeIgniter 4 host on its default MySQLi driver implements `IHostConnection` in about 40 lines over `$db->connID` and `transBegin/transCommit/transRollback`, or builds `PdoConnection` only if its domain writes also go through that PDO. The recipe in `examples/plain-php-durable/` shows both.
- Errors: every method throws on failure; nothing returns `false`.
- Binding: `PdoConnection` binds `int` parameters with `PDO::PARAM_INT` (so `LIMIT ?` works under emulated prepares, PDO MySQL's default) and everything else as strings. The pdo conformance suite runs once more with `ATTR_EMULATE_PREPARES = true`.
- `isDuplicateKey` matches the driver error code (MySQL 1062, Postgres 23505) only, never the SQLSTATE class 23000, which also covers FK and NOT NULL violations and would make `insertIgnited` silently drop an ignition.
- `DurableRuntime::compose()` builds the command bus, the pdo adapter set and the subscription registry from its arguments through `SubscriptionRegistrar` (3.5); nothing is discovered from globals or service-id conventions.

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
  public function replay(int $dlqId): void;                             // keeps event_id: resets the original outbox row, deletes the DLQ row (as today), one tx
  public function discard(int $dlqId): void;
  public function purge(\DateTimeImmutable $olderThan): int;            // accepted/completed rows only
  public function stats(): array;
}
```

- Errors: `append` throws, so a failed outbox insert rolls back the command (C14). `accept`, `retryLater` and `deadLetter` are fenced with `WHERE event_id = ? AND claim_token = ?`: 0 rows means the lease was lost, the result is discarded and logged, and nothing throws (C16-C18, E F4). The lease comes from `OutboxConfig::lock_timeout_seconds`, not a literal 300 (C15). Status vocabulary of the port: `pending`, `accepted` (transport has it), `dlq`, `cancelled` (C19). **wp keeps writing `completed`** (the existing MySQL ENUM, R4/R5), and `accepted` is a read alias in the wp port, so a rolled-back 0.6 winner still purges and counts those rows; pdo and sf store `accepted`. The wp `claim` sets `locked_until` / `locked_by` **as well as** `claim_token`, so a 0.6 copy running alongside N during a deploy keeps excluding claimed rows. `cancel_duplicates` touches only unleased `pending` rows with the same payload signature (C26).
- Replay keeps `event_id` (C22). That is possible without relaxing `uniq_event_id` because `move_to_dlq` leaves the original outbox row in place with status `dlq` (`OutboxRepository.php:262-270`) and purge deletes only `completed` rows (`PurgeOutboxHandler.php:26-28`). If the row is gone, replay re-inserts it with the original `event_id`. The legacy handler's new UUID (`ReplayDeadLetterHandler.php:33`) is dropped. Replay deletes the DLQ row as today (`ReplayDeadLetterHandler.php:58`); no resolution column is added. Extraction branch only: the 0.6.7 hotfix keeps the fresh UUID (section 6).
- Retry keeps the shipped int-id command shape: `RetryDeliveryCommand` still carries the integer `outbox_id` (`RetryDeliveryHandler.php:31`), and the handler maps it to the row's `event_id` before calling `retry()`.
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
final class Subscriber {
  public const LISTENER = 10; public const IGNITION = 50; public const RESUME = 99;   // frozen named phases (ProcessRunner.php:95,180)
  public function __construct(public readonly string $id, public readonly int $priority /* any int; phases are the constants */,
                              public readonly string $eventClassOrMarker, public readonly \Closure $handle) {} }
interface ISubscriptionRegistry {
  public function add(Subscriber $s): void;                       // boot time only
  /** @return list<Subscriber> ordered by priority ascending, then registration */
  public function for(string $eventClass): array;                 // matches by is_a, so marker interfaces work (D2)
}
final class SubscriptionRegistrar {     // one composition path for wp, sf and pdo (ruling #80)
  public function __construct(ISubscriptionRegistry $registry, ProcessRunner $runner, ?ContainerInterface $services = null) {}
  public function registerListener(string|object $listener): void;              // IntegrationTranslator / listener class or instance, LISTENER unless declared
  public function registerProcess(string $processClass): void;                  // class-string<LongProcess>; reads #[StartsOn] (IGNITION) and #[Awaits] (RESUME) by reflection
}
interface IDeliveryLedger {
  public function delivered(string $subscriberId, string $eventId): bool;
  public function markDelivered(string $subscriberId, string $eventId): void;
  public function markFailed(string $subscriberId, string $eventId, string $error, int $attempt): void;
  public function attempts(string $subscriberId, string $eventId): int;         // the per-subscriber budget counter (5.1, D1)
}
final class IntegrationDelivery {        // the portable drain bracket (today integration_action, ddd-wordpress/integration-events.php:37-64; integration_listener at :88-104)
  public function deliver(string $eventClass, array $wrapped): DeliveryOutcome {} // unwrap → Correlation::within(for_fact(event_id)) → each subscriber
}
namespace TangibleDDD\Runtime;
final class OrderedListenerDispatcher implements \TangibleDDD\Application\Events\IDomainEventDispatcher {} // local, sync, class + marker, Reactions frame
interface IRelayWakeup { public function poke(string $consumerPrefix): void; }          // after commit; null default
interface ISubscriberProbe { public function hasSubscribers(string $integrationAction): ?bool; } // null = unknown
```

- Delivery is at-least-once per subscriber. `deliver()` skips subscribers already in the ledger, runs the rest in priority order, each command in its own transaction, records success after the subscriber returns, and records a failure and continues with the next subscriber if one throws. It returns `DeliveryOutcome{delivered, failed}`. The delivery runner retries the fact while `failed` is non-empty, and each retry runs only the failed subscribers (C20, X8). A crash between a subscriber's commit and `markDelivered` re-runs that subscriber, so listeners stay idempotent, helped by deterministic command ids (3.8).
- Budget: the handler-execution budget (5.1) is counted in the ledger per (subscriber, event_id). When `attempts()` reaches the budget, the core invoker stops retrying that subscriber and, if its command is an `IExternalEffectCommand`, dispatches its `failureCommand()` once (D1). Nothing is triggered from Messenger's `WorkerMessageFailedEvent`, because a message is one fact with many subscribers.
- wp scope: only callbacks registered through DDD (`integration_action()`, `integration_listener()`, the runner, `SubscriptionRegistrar`) are wrapped into the invoker, isolated and ledgered. Raw `add_action` callbacks on integration hooks are outside the guarantee: a throwing raw callback still aborts the rest of `do_action`, as in 0.6. This is documented, not fixed.
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
- Implementations: pdo: rows in `{prefix}_ddd_jobs` executed by `runOnce`. wp: rows in an additive `{prefix}_ddd_wakeups` table **and** an Action Scheduler action projected **at schedule time**, future-dated to `due_at`, on the **legacy hook with the legacy associative args** (`{prefix}_process_continue` with `['process_id' => int]`, `{prefix}_await_timeout` with `['process_id' => int, 'step_index' => int]`, `ddd-wordpress/hooks.php:168-181`, frozen in R4). A rollback to a 0.6 winner therefore still fires every pending timeout and continuation, including future-dated ones (B16). The intent row is the recovery ledger and the fencing source: `cancel` removes both the row and the AS action (`as_unschedule_action` with the same hook and args); a relay tick re-projects a due intent whose AS action is missing. sf: rows in `ddd_wakeups` relayed to the `ddd_wakeups` Messenger transport with no multi-day `DelayStamp` (E section 7). mem: `InMemoryWakeupScheduler` driven by `FrozenClock`.
- wp `Deliver` retries go to the new `{prefix}_ddd_redeliver` hook (5.1), which has no callback under 0.6: pending redeliver actions are **lost on rollback** (Action Scheduler fails them). `wp ddd drain --before-rollback` runs them to completion or DLQ first, and the rollback runbook requires it.
- **(wave 4, CR-WPC4-2, WPC4-R1) More N-only artifacts on wp.**
  - A fact whose Action Scheduler args would exceed 8000 bytes is scheduled by reference: `WpLargeEnvelope::MARKER` points at the payload in the consumer's `{prefix}_integration_outbox` row. A 0.6 winner cannot resolve the reference, so the action is N-only. `wp ddd drain --before-rollback` (`WpRollbackDrain`) runs the due ones. It does not run future-dated ones early, because the delay belongs to the fact, so it counts them as remaining.
  - ResumeRetry intents are projected to `{prefix}_ddd_wakeup`, which has no 0.6 callback. The drain runs them.
  - Before every round the drain re-schedules redeliveries Action Scheduler lost, and re-projects pending intents that have no action. The command fails while anything remains.
  - The runbook is [docs/runbooks/rollback.md](../runbooks/rollback.md).

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
- Names: MySQL (wp, pdo) `ddd:` + sha1(`consumer|tenant|process_id`) truncated to 64 characters (the GET_LOCK limit). wp **also** takes the legacy name `ddd_process_<id>` during the compatibility window, always in the same order after the new name, so mixed 0.6/0.7 requests on one site still exclude each other (B21, C3). The legacy name has no consumer, tenant or database component and GET_LOCK names are server-global, so on wp two consumers (or subsites, or installs on one MySQL server) with the same process id still serialize against each other while it is taken. That is a known serialization cost, not a correctness issue; `lock.namespace` is `-` for wp until the legacy name is retired. Postgres: see 5.2.
- Fencing: every `IProcessStore::save` checks the row `version` (3.8), so a holder whose session lock vanished (reconnect) cannot overwrite a newer state. Before each step dispatch the runner does a fenced version touch (`UPDATE … SET version = version + 1 WHERE id = ? AND version = ?`); 0 rows aborts the wake before any step command runs. The fence protects the process row only: step effects are protected by idempotency (deterministic command ids `uuid5(process_id, step_index)` plus the D1 journal), not by the lock.

### 3.8 Process store, ignition, effects, codec

```php
namespace TangibleDDD\Runtime\Process;
enum IgnitionResult { case Inserted; case AlreadyIgnited; }
interface IProcessStore {
  public function insertIgnited(LongProcess $p, string $processClass, string $eventId): IgnitionResult; // #[StartsOn] path only;
                                                                       // sets ignition_key = uuid5(event_id, process_class); unique-constraint gate
  public function insert(LongProcess $p): int;                         // manual start; ignition_key NULL, ignited_by_event_id as today
  public function find(int $id): ?LongProcess;                         // call under the lock
  public function save(LongProcess $p, int $expectedVersion): int;     // new version; throws ConcurrentProcessModification on 0 rows
  /** @return list<int> */
  public function findWaitingFor(string $eventClass, ?string $awaitKey = null): array; // indexed (process_waits), ids only (E F14)
  /** @return list<StrandedProcess> */
  public function findStranded(\DateTimeImmutable $now): array;        // running/scheduled with no live intent past threshold
}
final class ProcessStoreFailed extends \RuntimeException {}           // insert/update failure; never set_id(0) (C27, C R12)
final class QuarantinedProcess extends \RuntimeException {}           // unknown stored class → status 'failed' + quarantine_reason set (E F12, R5)

namespace TangibleDDD\Runtime\Effects;             // D1
interface IExternalEffectCommand extends \TangibleDDD\Application\Commands\ICommand {
  public function idempotencyKey(): string;
  public function perform(): EffectResult;          // outside the tx
  public function record(EffectResult $r): void;    // inside the tx (Transaction middleware)
  public function failureCommand(\Throwable $last): ?\TangibleDDD\Application\Commands\ICommand;
}
interface IEffectJournal {
  public function find(string $key): ?EffectResult;
  public function store(string $key, EffectResult $r): void;
  public function invalidate(string $key, string $reason): void;   // explicit repair path; runs in the repair command's tx
}

namespace TangibleDDD\Runtime\Codec;               // D6
final class LargeString { /* base64-wrapped scalar with declared max bytes */ }
final class PayloadTooLarge extends \DomainException {}   // thrown at append, before commit
```

- Ignition (X7): `insertIgnited` is called only from the `#[StartsOn]` ignition path. It writes `ignition_key = uuid5(event_id, process_class)` under `UNIQUE (process_class, ignition_key)` and returns `AlreadyIgnited` on a duplicate key (MySQL 1062, Postgres 23505); the runner then returns without running a step (bug 2). On wp it also checks `long_processes.ignited_by_event_id` for the same class inside the ignition lock, so a saga ignited by a 0.6 winner between a rollback and a roll-forward is still seen. Manual starts (`insert`) keep `ignition_key` NULL and are never deduped, even inside a fact drain, where they keep stamping `ignited_by_event_id` as today (`process.manual-start-in-drain`).
- Quarantine: an undecodable row gets status `failed` and a non-null `quarantine_reason`; the worker continues. No new status value is written (R5).
- sf start (X3, 5.2): `ProcessRunner::start()` persists the process and writes a `Continue` intent in the caller's transaction, and the first step runs in a worker. `ddd.process.inband_start: true` restores the in-band first step and requires the direct connection; the bundle refuses it at boot on a pooled DSN.
- Ordering (D3, C10, E F2): a step's await is persisted with the checkpoint and its wakeup intents **before** the step's commands dispatch; a `IPrecheckAwait::already_satisfied()` hook absorbs a fact that committed before suspension.
- Resume and continuation re-read under the lock (C6, C7, E F1).
- Deterministic command id inside a fact cause: `uuid5(event_id, subscriber_id)`; inside a process step: `uuid5(process_id, step_index)`. `command_id` stays random outside those causes (E F3).
- D1 journal: keyed by the declared `idempotencyKey()`; command id is for tracing only. Repair is explicit: a repair command (TXP's `RepairStripeCustomer`) calls `IEffectJournal::invalidate(key, reason)` inside its own transaction before it re-dispatches, so the effect performs again; re-dispatching under a new command id alone does not bypass the journal. Budget exhaustion is counted in the per-subscriber delivery ledger and the core invoker fires `failureCommand()` (3.5, 5.1). Inside a process step, D1 perform retries follow the step's retry policy (default 0 → compensate); the journal makes a process-level retry of the step reuse the recorded result.
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
| D1 ExternalEffect | `IExternalEffectCommand`, `IEffectJournal` (with `invalidate`), `EffectMiddleware` (between Correlation and Transaction), ledger-counted budget firing `failureCommand()` from the core invoker | core + sf journal table | yes |
| D2 marker subscription | `ISubscriptionRegistry::for()` by `is_a`; `OrderedListenerDispatcher` | core + sf compile-time map | yes |
| D3 keyed await | await keys, `AwaitAny`, dynamic `AwaitAll`, precheck, persist-before-dispatch, `findWaitingFor(class, key)` | core + sf `process_waits` | yes |
| D4 lock scope | `IProcessLock` + section 5.2 | core contract + sf | yes |
| D5 actors | `IActorProvider`, `ActorKind::Machine` | core + sf providers; TXP authenticators set the actor | yes |
| D6 large strings | `LargeString`, cap at append, quarantine | core + sf column types | yes |
| D7 durable alarms | `IWakeupScheduler` with absolute `due_at` | core + sf | yes |
| D8 redaction | `Redactor` extension, `#[Sensitive]`, `#[NotAudited]` | core | yes |
| D9 operator view | `IOperatorView` | core port + sf merge (incl. Messenger failure transport) | yes; TXP's unacked-job layer is TXP's |
| D10 workflow ignition | ignition ledger with caller-supplied dedup key (`#[StartsOn]` for workflows) | core + sf (`DbalBehaviourWorkflowRepository`, `DbalWorkItemRepository`, workflow ignition ledger with a unique dedup key, wave 3) | yes |
| D11 return values | already works; receipt-rule docblock relaxed | core | yes (docs) |
| D12 audit policy | `IAuditPolicy`, `#[Audit(false)]` | core | yes |
| D13 cause + uuid5 | `Correlation::current_fact()`, `Uuid::v5`, deterministic ids | core | yes |
| D14 post-commit wakeup | `IRelayWakeup` + sf transactional `NOTIFY` / `LISTEN` relay with poll fallback | core no-op + sf | yes |

All fourteen are generic and live in the library (E section 9; this answers module-map Gate 1 decision 10). TXP-local code implements only its domain effects, authenticators and the unacked-job layer.

---

## 4. Shared conformance scenarios

The suite lives in `packages/ddd-conformance/` as abstract PHPUnit cases; each adapter extends them with a fixture factory. Names are stable identifiers, used in test method names and CI output. Host columns: **mem** (in-memory doubles, same process), **pdo** (MySQL 8, separate `php` processes where marked), **wp** (WP 7.1.2 + MySQL 8, AS runner), **sf** (Postgres 16, Messenger). **A cell is the wave (1-4) at whose acceptance the scenario must first pass on that host and keep passing afterwards; "-" = not applicable.** Section 8 repeats the same assignment as exact id lists per wave per host; the two must agree, and a change to either is a register edit.

Scenarios that used to mix delivery mechanics with process machinery are split (ruling on #71): the plain id is the **process-free** variant (subscriber ledger and priority order, with stub subscribers registered at `Subscriber::IGNITION` and `Subscriber::RESUME`), and the `.process` id is the **process** variant with the real runner, which needs the wave-3 runner and stores. All scenarios run on the extraction branch; the hotfix line has its own, smaller set (section 6).

| Id | Scenario | Expected result | mem | pdo | wp | sf |
|---|---|---|---|---|---|---|
| `cmd.commit-atomic` | handler writes domain row, reaction stages a fact, commit | domain row and outbox row both present | 1 | 3 | 2 | 2 |
| `cmd.commit-failure` | inject failure on COMMIT | no domain row, no outbox row, original exception surfaces, audit shows error | 1 | 3 | 2 | 2 |
| `cmd.reaction-throws` | in-tx reaction throws | full rollback; nothing relayed | 1 | 3 | 2 | 2 |
| `cmd.no-boundary` | `ITransactionalCommand` with no boundary | `NoTransactionBoundary` before the handler runs | 1 | 3 | 2 | 2 |
| `cmd.nested-rejected` | outer tx open, policy Reject | `NestedTransactionRejected`; outer tx untouched | 1 | 3 | 2 | 2 |
| `cmd.guards-without-audit` | nested command, post-seal event, re-raised fact, audit off | existing guards still throw | 1 | 3 | 2 | 2 |
| `cmd.return-value` | command returns a DTO through all middleware | value returned unchanged (D11) | 1 | 3 | 2 | 2 |
| `relay.fresh-process-pickup` | process A commits a fact and exits; process B (fresh `php`) runs `runOnce`/worker | B delivers it; no state shared except the DB | - | 3 | 3 | 3 |
| `relay.crash-after-commit` | commit, kill before any relay | fact still pending; next run delivers once | - | 3 | 3 | 3 |
| `relay.crash-after-submit` | transport accepted, crash before `accept` | shared-connection transports: both roll back, one delivery; others: same `event_id` may recur, subscriber effect idempotent | 1 | 3 | 3 | 2 |
| `relay.lease-fencing` | A claims, lease expires, B claims and accepts, A finishes late | A's `accept`/`retryLater` affect 0 rows; on wp a 0.6 fetch also skips the claimed row (`locked_until` set) | 1 | 3 | 3 | 2 |
| `relay.invalid-acceptance` | transport throws or returns no ref | retry per relay budget, then DLQ; never marked accepted | 1 | 3 | 2 | 2 |
| `relay.pause-holders` | two holders overlap, one released, one expires | remaining hold still pauses; expiry honoured | 1 | 3 | 3 | 2 |
| `relay.replay-keeps-identity` | replay a DLQ row (process-free) | same `event_id` on the reset outbox row; DLQ row deleted; subscribers already in the ledger are skipped | 1 | 3 | 2 | 3 |
| `relay.replay-keeps-identity.process` | replay a DLQ row whose fact ignites a process | same `event_id`; no second process | 3 | 3 | 3 | 3 |
| `delivery.double-delivery` | the same fact delivered twice; stub ignition and resume subscribers | each subscriber's effect applied once (ledger + idempotent handler), stubs included | 1 | 3 | 3 | 2 |
| `delivery.double-delivery.process` | the same igniting and awaited fact delivered twice, real runner | ignition once; resume once | 3 | 3 | 3 | 3 |
| `delivery.subscriber-isolation` | listener A ok, listener B throws once; stub ignition and resume subscribers | both stubs still run; retry runs only B | 1 | 3 | 3 | 2 |
| `delivery.subscriber-isolation.process` | as above with a real ignition and a real resume | ignition and resume still run once; retry runs only B | 3 | 3 | 3 | 3 |
| `delivery.phase-order` | stub subscribers at priorities 100, 99, 50, 10 registered in that order | run 10 → 50 → 99 → 100 | 1 | 3 | 2 | 2 |
| `delivery.phase-order.process` | one fact starts process B and is awaited by suspended A | B ignited first; order listener → ignition → resume | 3 | 3 | 3 | 3 |
| `delivery.delayed-once` | fact with `delay() = D` published at t0 | due at t0 + D ± tick, delivered once; a retry adds no delay; a legacy row with `delay_seconds > 0` and past `scheduled_at` is enqueued immediately | 1 | 3 | 2 | 3 |
| `lock.contention` | connection 2 holds the process lock; a wake arrives | wake waits up to the timeout, then `LockNotAcquired`; row unchanged; wake re-queued and later succeeds ("later succeeds" is extraction-branch only, section 6) | 3 | 3 | 3 | 3 |
| `lock.acquire-error` | lock backend returns NULL / false / error | `LockNotAcquired`; critical section never entered; no save | 3 | 3 | 3 | 3 |
| `lock.reentrant-balance` | timeout path acquires, then `with_process` acquires again | balanced; `heldCount() === 0` at the end | 3 | 3 | 3 | 3 |
| `lock.namespace` | two consumers, same process id | no cross-blocking (wp: `-` while the legacy `ddd_process_<id>` name is also taken, 3.7) | - | 3 | - | 3 |
| `process.ignition-race` | two workers deliver the same igniting fact concurrently | exactly one process row; the loser runs no step | 3 | 3 | 3 | 3 |
| `process.manual-start-in-drain` | a listener running inside a fact drain calls `start()` twice for the same process class | two process rows, both with `ignited_by_event_id` = the fact id and `ignition_key` NULL; neither is deduped; a later `#[StartsOn]` ignition of that class by the same fact still ignites once | 3 | 3 | 3 | 3 |
| `process.await-all-concurrent` | two keys of a 2-key AwaitAll delivered concurrently | resumes once with both keys | - | 3 | 3 | 3 |
| `process.timeout-vs-event` | timeout and awaited fact race | exactly one of resume or timeout applies; no resurrection after compensation | 3 | 3 | 3 | 3 |
| `process.await-before-dispatch` | awaited fact delivered synchronously inside the step's dispatch | process still resumes | 3 | 3 | 3 | 3 |
| `process.intent-survives-queue-failure` | process save succeeds, transport unavailable | intent row remains; a later run wakes the process | 3 | 3 | 3 | 3 |
| `process.stale-wakeup` | continuation for a step already passed | no-op | 3 | 3 | 3 | 3 |
| `process.crash-mid-step` | kill after the step's command commits, before checkpoint | stranded item in operator view; resume re-runs the step with the same deterministic command id | - | 3 | 3 | 3 |
| `process.fresh-process-resume` | P1 suspends; P2 (fresh) delivers the awaited fact; P3 (fresh) fires the stale timeout | completes once; timeout no-op (C section 8) | - | 3 | 3 | 3 |
| `process.start-from-web` | `ProcessRunner::start()` from a web request on a pooled connection; then again with `ddd.process.inband_start: true` | process row and `Continue` intent commit with the caller's transaction; no advisory lock is taken in the request; the first step runs in the worker; the in-band opt-in on a pooled DSN is refused at boot | - | - | - | 3 |
| `process.alarm-long` | 25 h alarm, worker restarted, fake clock advanced | fires once | 4 | 4 | 4 | 4 |
| `process.await-keyed-precheck` (D3, CR-W4C4-1) | keyed await on a ref the step mints (`step_ref`), with a checkpoint and an alarm; then a precheck process whose job result committed during the step's dispatch | at dispatch the await, its `(class, ref)` route, the checkpoint and the alarm are committed; a foreign key writes nothing; the minted key resumes only its process and cancels the alarm; the precheck resumes in the same wake; the late fact completes with no error and no retry, and writes nothing | 4 | 4 | - | 4 |
| `process.await-any-cancellation` (D3, CR-W4C4-1) | three `AwaitAny(keyed answer).cancelled_by(WidgetScrapped{widget}).within(1h)` processes, two on one widget | the cancellation compensates both processes of its widget, once each (`Cancelled by WidgetScrapped`), with no intent left, and leaves the third alone; a late answer does not resurrect a cancelled process; the third resumes on its own answer; a cancellation after completion is a no-op | 4 | 4 | - | 4 |
| `process.await-all-dynamic` (D3, CR-W4C4-1) | `AwaitAll::keyed` over keys computed and checkpointed at step time (`children_first`) | the routes shrink as keys arrive; a duplicate, unknown or other process's key writes nothing; the alarm is not re-delayed; resumes once with every key; an empty set does not suspend | 4 | 4 | - | 4 |
| `workflow.fact-ignition-once` (D10) | the same `CronEntryDue` fact delivered twice, and two cron ticks in one minute | exactly one workflow run | 4 | - | - | 4 |
| `worker.no-leak` | two messages in one worker, first fails | second sees `Correlation::peek() === null`, empty UoW, null resume argument, zero held locks | 1 | 3 | 3 | 2 |
| `audit.sink-fails` | audit sink throws after domain commit | business result committed; signal emitted | 2 | 3 | 2 | 3 |
| `codec.large-payload` | 1 MB binary string field | round-trips via `LargeString` or fails before commit with `PayloadTooLarge` | 4 | 4 | 4 | 4 |
| `decode.unknown-class` | stored process class no longer exists | status `failed` with `quarantine_reason` set; worker continues | 4 | 4 | 4 | 4 |
| `effect.journal-reuse` (D1) | `perform` ok, `record` throws, redelivery; then budget exhaustion; then a repair | `perform` not called again; `record` runs with the journaled result; when the subscriber's ledger attempts hit the budget, the core invoker commits the failure command once; a repair that calls `invalidate(key, reason)` in its transaction performs again | 4 | 4 | - | 4 |
| `wakeup.post-commit` (D14) | commit, NOTIFY suppressed, rollback | delivered < 1 s with NOTIFY; within the poll interval without; nothing on rollback | - | - | - | 4 |

Counts: 47 scenario ids. The wave-0 revision had 44: the original 37, plus 4 `.process` variants, plus the three new ids `process.manual-start-in-drain`, `process.start-from-web` and `workflow.fact-ignition-once`. `relay.replay-keeps-identity` kept its id as the process-free variant. Wave 4 added the three D3 ids (CR-W4C4-1). wp runs D1 and D10 only when a WP consumer needs them (O9). The D3 cells are `-` on wp because wp's 7.3 rollback fixtures do not cover keyed awaits, `AwaitAny` or a dynamic `AwaitAll` (a 0.6 winner cannot decode them). The wp adapters can express these scenarios, so a cell becomes a wave once those fixtures exist. D14 is sf-only because only Postgres has transactional NOTIFY; other hosts rely on polling, which `relay.fresh-process-pickup` already covers. On wp, the fenced relay, ledger and pause scenarios wait for schema v8 (wave 3); wave 2's transitional wp adapters cover only what runs on the 0.6 schema. The pdo conformance suite runs twice, the second time with `ATTR_EMULATE_PREPARES = true` (3.3).

---

## 5. Retry budgets, Postgres lock, recoverable state

### 5.1 Retry budget split and the one failure view

| Layer | Owner | What it retries | Default budget | Exhaustion goes to |
|---|---|---|---|---|
| Relay submission | `OutboxProcessor` (core) | `ITransport::submit` failures only | `OutboxConfig::max_attempts` = 5, backoff 60 s × 2^n capped at 3600 s (`OutboxConfig.php:13-18`) | relay DLQ (`{prefix}_integration_dlq` / `ddd_dlq`), layer `relay` |
| Handler execution | delivery runner: wp ledger + `{prefix}_ddd_redeliver` AS hook (new name, B16; lost on rollback unless `wp ddd drain --before-rollback` ran, 3.6); pdo `{prefix}_ddd_jobs.attempts`; sf Messenger `retry_strategy` on `ddd_facts` | failed subscribers of one fact only | 5 attempts, backoff 30 s × 2^n capped at 3600 s, counted in the delivery ledger per (event_id, subscriber_id) | ledger status `failed` (+ sf failure transport `ddd_failed`), layer `delivery`; D1 `failureCommand()` fired by the core invoker |
| Wake execution | `IWakeupScheduler` retry | `LockNotAcquired`, transient DB errors | 10 attempts, backoff 2 s × 2^n capped at 300 s | stranded process, layer `wakeup` |
| Step failure | runner compensation | none by default (a step failure compensates, as today); a step's own retry policy also governs D1 perform retries inside it | 0 | process `failed`, layer `process` |
| Workflow item | `WorkflowHandler::$max_retries` | per item | 3 (unchanged) | layer `workflow` |

Rules: the budgets never multiply. The relay cannot see handler failures, and a handler retry never re-submits the outbox row. With a shared-connection transport (AS on `$wpdb`, the pdo jobs table, the Doctrine transport on the domain DBAL connection) submission and `accept` commit together, so relay failures are DB errors only. D1's failure command fires when a subscriber's **handler** budget, counted in the delivery ledger, is exhausted; the core invoker fires it, on every host, and never from `WorkerMessageFailedEvent` (a Messenger message is one fact with many subscribers). Inside a process step, D1 follows the step's retry policy (default 0 → compensate) and the journal makes a process-level retry reuse the recorded result. One operator view (`IOperatorView`, 3.10) lists all layers with attempts against budget. It is exposed through the WP dashboard plus a new `wp ddd ops` command, sf `ddd:ops:list` / `ddd:ops:repair`, and for pdo `OperatorView::list()` returning arrays the host renders (no UI). This answers review finding 7.

### 5.2 Postgres lock choice and lifetime (answers review finding 9, module-map Gate 1 decision 12)

- **Choice: session-scoped `pg_try_advisory_lock(bigint)`**, polled every 50-200 ms (jittered) until a 5 s deadline, matching `GET_LOCK(…, 5)` (`ProcessRunner.php:397`). The key is `(crc32(consumer_prefix) << 32) | (process_id & 0xffffffff)`. A hash collision only adds serialization, because state is always re-read under the lock.
- **Lifetime:** one wake. Acquire, re-read, act (several independently committed step commands), release with `pg_advisory_unlock` in `finally`. Nested acquisition goes through `ReentrantProcessLock`, so Postgres sees one acquisition per wake. Held zero times at every message boundary (reset guard).
- **Why not transaction-scoped:** `pg_advisory_xact_lock` would be released at the first step command's commit, mid-wake (`ProcessRunner.php:619-628`). It would only work if a wake became one transaction, which contradicts the committed-steps-then-compensate model (`:516-586`).
- **Fencing:** process rows carry `version`; every save is `WHERE id = ? AND version = ?`, and before each step dispatch the runner does a fenced version touch. A holder whose connection reconnected under it fails the touch or the save with `ConcurrentProcessModification` and the wake retries. Step effects already dispatched are protected by idempotency (deterministic command ids plus the D1 journal), not by the lock or the fence.
- **Connection prerequisite:** workers and `ddd:relay` use a direct, non-pooled connection (also needed for `LISTEN`). The bundle warns at boot when the DSN host matches a known pooler pattern (`-pooler`, port 6432). Web requests may use a pooled connection because on sf they never take process locks: `ProcessRunner::start()` persists the process and writes a `Continue` intent in the caller's transaction, and the first step runs in a worker. An in-band first step is opt-in (`ddd.process.inband_start: true`), requires the direct connection, and is refused at boot on a pooled DSN (scenario `process.start-from-web`). Neon provides a direct endpoint, which TXP must configure for workers (O7).
- **Not used:** Symfony Lock `PostgreSqlStore` (no re-entrancy guarantee matching the nesting, and an extra dependency).
- Row locks for TXP invariants (staging cap, name registry) are ordinary `SELECT … FOR UPDATE` inside a command transaction and belong to TXP.

### 5.3 Making process state and wakeup intent recoverable

1. **One transaction per state change.** The runner wraps "save process (version-checked) + write/cancel wakeup intents + write await rows" in `ITransactionBoundary::run` on the process store's connection. Step commands still commit in their own transactions, before or after, as today.
2. **Await before dispatch.** Checkpoint, await and timeout intent commit first, then the step's commands dispatch (C10, E F2).
3. **Intent rows are the source of truth; the transport is a projection.** A relay step (in `runOnce`, the wp relay tick, or sf `ddd:relay`) moves due intents to the transport. Losing a transport message, or a crash between save and enqueue, leaves the intent due, and the next tick re-projects it (C8, C9). Post-commit wakeup (D14) only shortens latency. **wp differs:** it projects every intent to Action Scheduler at schedule time, future-dated, on the legacy hook with the legacy associative args (3.6), so a rolled-back 0.6 winner still fires it; the relay tick only re-projects intents whose AS action is missing.
4. **Stale-safe handlers.** Each wake re-reads under the lock and checks `expectedStatus` / `stepIndex`.
5. **Stranded scan.** `findStranded()` runs in every relay tick: `scheduled` rows with no live intent get a fresh `Continue` intent automatically (continuation is stale-safe); `running` rows whose lock is free and whose `updated_at` is older than a threshold (default 15 min) are reported in the operator view only, with `ResumeStrandedProcess` / `FailStrandedProcess` repairs, because an automatic re-run would repeat step effects. On wp the scan first checks `as_has_scheduled_action(hook, args)` for the legacy hook and args and mints no intent when a 0.6-queued action already exists (the legacy `['process_id']` arg cannot carry `stepIndex`, so a duplicate would re-run a step after a rollback). The schema v8 migration also backfills intent rows from pending AS actions, so a `scheduled` row left by 0.6 is not stranded on upgrade.
6. **Ignition is gated by the unique constraint** on `(process_class, ignition_key)` inside the same transaction as the initial save (X7, 3.8); on wp the same lock also covers the `ignited_by_event_id` check.

---

## 6. The three bug fixes: frozen intended behaviour

| Bug | Code today | Intended behaviour (frozen) | Scenario | Hotfix 0.6.7 (prepared, unreleased) | Extraction branch |
|---|---|---|---|---|---|
| **1. GET_LOCK NULL runs unlocked** | `(string) $acquired === '0'` at `ProcessRunner.php:399`; NULL → `''` passes. Masked by `tests/wp-stubs.php:23` returning null and `AwaitTimeoutTest.php:23-25` | Only a definite acquisition enters the section. Timeout, NULL and query error all mean "not acquired": no step runs, no save, the exception propagates, and the wake is re-queued, not lost. Lock names are namespaced by consumer and tenant (extraction branch; on wp only once the legacy name is retired, 3.7). | `lock.acquire-error`, `lock.contention`, `lock.reentrant-balance`; plus `lock.namespace` on pdo and sf only | `if ($acquired === null \|\| (string) $acquired !== '1') throw new LockingException(...)` with the error or timeout reason; the stub returns `'1'` for GET_LOCK/RELEASE_LOCK; the false comment at `:392,400` corrected. **Lock name unchanged.** **Weaker guarantee:** on a NULL or contended lock the wake is not re-queued; Action Scheduler records the failed action, which is visible and manually retryable in the AS admin. | `IProcessLock` + `ReentrantProcessLock`; wp takes the new and the legacy names; re-queue via `ResumeRetry` intent |
| **2. Ignition check-then-insert** | `has_ignition` SELECT (`ProcessRepository.php:90-100`) on the `#[StartsOn]` path (`ProcessRunner.php:166-175`), then a separate insert in `start()`'s save (`:228`); index `idx_ignition` not unique (`ddd-wordpress/tables.php:131`) | For a given `(process_class, event_id)` exactly one **ignited** process row ever exists, however many deliveries, replays or workers. The loser returns quietly without running a step. Manual starts, including those made inside a fact drain (which stamp `ignited_by_event_id`), are never deduped. | `process.ignition-race`, `process.manual-start-in-drain`, `delivery.double-delivery.process`; extraction branch only: `relay.replay-keeps-identity.process` | Schema-free: after bug 1, a named lock `ddd_ign_` + md5(prefix\|class\|event_id) around re-check + insert on the `#[StartsOn]` path only (split `start()` into persist and run). **Out of scope:** `ReplayDeadLetterHandler` still mints a fresh UUID, so replaying an igniting fact still double-ignites on 0.6.7. | `insertIgnited` on `UNIQUE (process_class, ignition_key)` (X7); replay keeps `event_id` |
| **3. Delayed events delayed twice** | `scheduled_at = now + delay` (`OutboxRepository.php:32`), fetch gated by `scheduled_at <= now` (`:97`), then the AS publisher adds `delay_seconds` again (`ActionSchedulerOutboxPublisher.php:21-27`), again on every retry. Masked by `tests/Fakes/FakeOutboxRepository.php:53-54` | The due time is absolute UTC on the row and honoured once. The publisher schedules at `max(now, scheduled_at)`; when that is not in the future it enqueues async. Retries are gated by `next_attempt_at` only. Rows written by older code (with `delay_seconds` and `scheduled_at` set) are not delayed again. `{prefix}_outbox_publish_external` hookers are documented not to add `delay_seconds`. | `delivery.delayed-once`, `process.alarm-long` | Same publisher change; changelog "behaviour fix: delays halve to their declared value". AS actions already queued keep the doubled delay. | `ITransport::submit(..., $dueAt)` with no relative delay anywhere |

Every fix lands test-first: a unit test that fails on `598858c`, then the fix. **Sequencing (#66):** wave 1 applies the schema-free variants (the hotfix column) to **both** branches; the extraction variants need wave-3 ports and schema v8 and replace them on the extraction branch in wave 3. The hotfix branch is `hotfix/0.6.7` from tag `v0.6.6` and is never tagged in this project. Its diff against `v0.6.6` is exactly the three fixes, the `wp-stubs.php` change, the `symfony/yaml` require (O11) and tests; the version-unique loader entry lands on the extraction branch only (O1 rejected for the hotfix).

**Hotfix 0.6.7 guarantees, stated plainly.** (a) A NULL, errored or contended GET_LOCK throws `LockingException` and runs nothing unlocked; the wake is **not** re-queued, and Action Scheduler records the failed action, which an operator can see and retry by hand in the AS admin. (b) Ignition via `#[StartsOn]` is exactly-once per `(class, event_id)` across concurrent deliveries and redeliveries, but a DLQ **replay** of an igniting fact still double-ignites, because replay still mints a new event id. (c) Delays are honoured once for facts written after the upgrade; AS actions already queued keep the doubled delay. (d) No schema change and no lock-name change. The hotfix test set is therefore `lock.acquire-error`, `lock.reentrant-balance`, `lock.contention` without "later succeeds", `process.ignition-race`, `process.manual-start-in-drain` and `delivery.delayed-once`, run as legacy-suite tests on the 0.6 code; `lock.namespace`, `relay.replay-keeps-identity(.process)` and the "later succeeds" half of `lock.contention` are extraction-branch only. Only cred ignites processes (`#[StartsOn]`) or emits delayed facts (D F8), so cred's `EndpointAuthRefresh` and `BehaviourWorkflowReschedule` delays shorten after bug 3 (O10).

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
| Historical scaffold YAML | `class-ddd-command.php:407-605` from the tags that exist: `v0.6.0`, `v0.6.2`, `v0.6.3`, `v0.6.4`, `v0.6.5`, `v0.6.6` (there is no `v0.6.1` tag), plus cred's `includes/di/*.yaml` | compile matrix (B9) |

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
| `load.v0-2-negative` | L-0.2.5 + N | unsupported by decision (X1). The natural behaviour is a silent mixed load, so N detects it actively: at winner boot it probes for 0.2-only FQCNs (e.g. `CorrelationContext`) and raises a named unsupported-version error when `WP_DEBUG` is on, logging the same message otherwise. Pass = the named error (debug) or log line (non-debug) appears; never silent |

### 7.3 Pending-row fixtures (written by a legacy winner, drained by N, and the reverse)

- Outbox rows `pending`, `dlq` (with DLQ row), leased (`locked_until` in the future), delayed (`delay_seconds > 0`), `is_unique`; the pause option `{prefix}_outbox_pauses`.
- Pending AS actions: `{prefix}_integration_*` with wrapped envelopes, `{prefix}_process_continue` with `['process_id' => int]`, `{prefix}_await_timeout` with `['process_id' => int, 'step_index' => int]`, and the recurring `{prefix}_outbox_process`.
- Processes: `running`, `scheduled`, `suspended` with `AwaitEvent` and `AwaitAll` serialized by 0.6.5, compensating, and pre-existing duplicate ignitions.
- Behaviour workflows with work items and meta rows; command audit rows.
- Rollback: N writes all of the above, the winner switches to L-0.6.6 and L-0.6.2, queued work drains and decodes; schema `installed > DDD_SCHEMA_VERSION` is tolerated (B18). N's outbox rows are `completed`, never `accepted`, so the legacy purge and stats still see them; quarantined processes are `failed` with `quarantine_reason` set.
- N-only artifacts (written by N, then rolled back): future-dated wakeup intents (their AS actions must fire under 0.6), pending `{prefix}_ddd_redeliver` actions (expected: lost unless `wp ddd drain --before-rollback` ran first; the fixture runs both paths), failed delivery-ledger rows, rows with `ignition_key` and `quarantine_reason` set, and claimed outbox rows (`claim_token` plus `locked_until`/`locked_by`).
- Sequence fixtures: (1) upgrade → stranded scan → rollback → drain: no step re-runs, no duplicate `{prefix}_process_continue` action is minted; (2) upgrade → rollback → a 0.6 ignition → roll-forward → redeliver the same fact: no second process (the wp `insertIgnited` `ignited_by_event_id` check); (3) upgrade with 0.6-queued AS continuations and timeouts: the v8 migration backfills their intent rows.
- **Where the suite lives (CR-WPC4-6, recorded in wave 5).**
  - The fixtures are `tests/Compat/rollback/**`. The suite entry is `tests/Integration/Rollback/phpunit.xml`, which packaging's `HarnessCliTest` pins (PK4-1).
  - `tests/harness/run.sh compat` runs them in its 7.3 block (`compat_rollback()`). For every ref in `DDD_ROLLBACK_REFS` (default `v0.6.6 v0.6.5 v0.6.2`) it exports the legacy copy from the clone, runs `composer install --no-dev --no-scripts` on it, mounts it read-only at `/legacy`, and passes `DDD_ROLLBACK_LEGACY="<version>=<dir> ..."` to the suite.
  - Each legacy run is a php child (`tests/Compat/rollback/bin/legacy.php`). It loads WordPress and then only that legacy copy and its Action Scheduler, so the copy wins through its loader's late branch.
  - The cases are in `LegacyRowsDrainedByNRollback`, `NRowsRolledBackRollback` and `SequencesRollback`. There are 7 tests, each run against L-0.6.6, L-0.6.5 and L-0.6.2, so 21 cases. The item-by-item coverage table is in [wave4-wp-conf4-change-requests.md](wave4-wp-conf4-change-requests.md).
- **0.6 `#[Async]` defect (recorded, not changed).** Under every 0.6.x copy an `#[Async]` step re-schedules its continuation before it runs, on every continuation. The step never runs, and exactly one `{prefix}_process_continue` stays queued. N fixed this. After a rollback, a process that N left at an `#[Async]` step stalls under the 0.6 winner: no step re-runs, and there is never more than one continuation. N completes it after the roll-forward. The rollback fixtures pin both directions, and the runbook states it.

### 7.4 Supported and unsupported combinations

| Combination | Status |
|---|---|
| N alone; N + any L-0.6.2..0.6.7 in either order; N + P; N + N | **supported** |
| Legacy copies only (today's sites) | unchanged behaviour; the hotfix reaches a site only if it loads first or P is active (B1) |
| ddd-core + ddd-wp from different plugins or versions | **unsupported, refused**: ddd-wp checks its sibling core version by path and does not initialize on a mismatch (B D-3) |
| A WP plugin bundling ddd-core without `tangible/ddd` | unsupported: on WP, core is always the winner's core; documented |
| `tangible/ddd` + `tangible/ddd-core` in one vendor tree | impossible (`replace`) |
| `Defaults/Pdo` under WordPress | refused at ddd-wp boot (B24) |
| 0.1.x anywhere; the reporting 0.2 feature branch with any 0.6+ consumer | unsupported by decision (redeclare fatal / silent mixed load of removed classes, detected by `load.v0-2-negative`) |
| MariaDB | not claimed; no test leg |
| ddd-symfony workers on a pooled Postgres endpoint | unsupported; boot warning |

---

## 8. Implementation waves

Authors and the paths each owns. A path belongs to one author per wave, and the globs below are disjoint; a change outside one's paths goes through the owning author. `packaging` owns every `composer.json` in the repo **except** `packages/ddd-conformance/composer.json`, which conformance owns.

| Author | Owned paths |
|---|---|
| core | `packages/ddd-core/src/**` except `Defaults/Pdo/**`; `packages/ddd-core/tests/Unit/**`; `examples/plain-php/**`; **during wave 3 only** `tests/Unit/Process/**` (the ProcessRunner test migration, including `AwaitTimeoutTest`'s `$wpdb` stub; returns to wp at the end of wave 3) |
| pdo-default | `packages/ddd-core/src/Defaults/Pdo/**` (including `IHostConnection` from wave 1); `packages/ddd-core/schema/mysql8/**`; `packages/ddd-core/tests/Pdo/**`; `examples/plain-php-durable/**` |
| wp | `ddd-src/**` and `ddd-wordpress/**` until they are emptied (wave 1 fixes, wave 2 moves jointly with core), except `ddd-wordpress/self/index.php` from the end of wave 2; `packages/ddd-wp/{src,wordpress,tests}/**`; `tests/Unit/**` except `tests/Unit/Loader/**` (always packaging) and, during wave 3, `tests/Unit/Process/**` (core); `tests/Integration/**`; `tests/Fakes/**`; `tests/wp-stubs.php` |
| symfony | `packages/ddd-symfony/{src,schema,config,tests}/**`; `examples/symfony/**` |
| packaging | `composer.json`, `packages/{ddd-core,ddd-wp,ddd-symfony}/composer.json`, `tangible-ddd.php`, `loader/**`, `compat/**`, `ddd-wordpress/self/index.php` (the shim; handed from wp to packaging at the **end of wave 2**, after wp's last move batch), `.gitattributes`, `.github/workflows/**`, `deptrac.yaml`, `phpstan*.neon`, `tests/{Compat,Loader,harness}/**`, `tests/Unit/Loader/**` |
| conformance | `packages/ddd-conformance/**`, including its own `composer.json` |

Because every port interface and in-memory double lands in wave 1 (below), the wave-3 adapter authors (pdo-default, wp, symfony) do not wait on core; the former "core commits wave-3 interfaces as step 0" is moot.

### Wave 1: fix and fence (no file moves)

- **wp:** the three bug fixes in place, **schema-free variants** (the hotfix column of section 6) on both branches (`ddd-src/Application/Process/ProcessRunner.php`, `ddd-src/Infra/Persistence/{OutboxRepository,ProcessRepository}.php`, `ddd-src/Infra/Services/ActionSchedulerOutboxPublisher.php`, `tests/wp-stubs.php`, `tests/Fakes/FakeOutboxRepository.php`), each test-first; mirror onto `hotfix/0.6.7` from `v0.6.6`. The extraction variants replace them in wave 3. `symfony/yaml` stays a packaging change (below).
- **core:** new files only: **every port interface of sections 3.1-3.9** plus its in-memory double under `packages/ddd-core/src/{Runtime,Testing}/`: identity, clock (`SystemClock`, `FrozenClock`, test-only `EnvOffsetClock`), `ITransactionBoundary` + `TransactionalCommandMiddleware`, `IOutboxStore`, `IOutboxAdministration`, `IRelayPauseStore`, `ITransport`, `ISubscriptionRegistry` + `Subscriber` + `SubscriptionRegistrar`, `IDeliveryLedger`, `IntegrationDelivery`, `OrderedListenerDispatcher`, `IRelayWakeup`, `ISubscriberProbe`, `IWakeupScheduler` + `WakeupIntent`, `IProcessLock` + `LockKey` + `ReentrantProcessLock`, `IProcessStore` + `IgnitionResult`, `IExternalEffectCommand` + `IEffectJournal` (interfaces only; `EffectMiddleware` is wave 4), `IActorProvider`, `IAuditPolicy`, `IAuditSink`, `IEnvironmentProvider`, `IInfrastructureSignalDispatcher`, `IFactObserver`, `RuntimeReset`, `HostDefaults`, `Uuid::v5`. Not in wave 1: `LargeString`/`PayloadTooLarge` (D6, wave 4), `Drain` and `IOperatorView` (wave 3). **This freezes the outbox, relay, delivery, process, lock and scheduling contracts** (ruling on blocker #70), so tenancy-reference and every wave-3 adapter build against fixed interfaces.
- **pdo-default:** `IHostConnection` interface only (3.3) and the frozen `DurableRuntime::compose()` signature as a stub that throws `LogicException('wave 3')`.
- **conformance:** `packages/ddd-conformance/` skeleton with its own `composer.json`; on **mem**: `cmd.commit-atomic`, `cmd.commit-failure`, `cmd.reaction-throws`, `cmd.no-boundary`, `cmd.nested-rejected`, `cmd.guards-without-audit`, `cmd.return-value`, `relay.crash-after-submit`, `relay.lease-fencing`, `relay.invalid-acceptance`, `relay.pause-holders`, `relay.replay-keeps-identity`, `delivery.double-delivery`, `delivery.subscriber-isolation`, `delivery.phase-order`, `delivery.delayed-once`, `worker.no-leak` (17 ids). `lock.acquire-error` moved to wave 3.
- **packaging:** PHP floor 8.2 (manifest, plugin header, `LoaderIdentityTest`); `symfony/yaml` to `require` (F-5, also on the hotfix branch; O11); `packages/{ddd-core,ddd-wp,ddd-symfony}/composer.json` skeletons; `.gitattributes`; CI on `extraction/**` (wp-unit 8.2/8.4, wp-integration on Docker MySQL 8.0, static); hermetic harness (`WP_TESTS_ABSPATH`, pinned datastream ref, fresh DB, table-existence assertion; F-10..F-12); loader baseline fixture `load.legacy-first` recorded against today's code. The version-unique loader entry is not added to the hotfix branch (O1).
- Acceptance:
  - `vendor/bin/phpunit` → 628 + new tests green; the new bug tests fail on `598858c` (`git stash`-style check in CI log).
  - `tests/harness/run.sh wp-integration` → green on MySQL 8.0 from an empty DB.
  - `cd packages/ddd-conformance && composer install && vendor/bin/phpunit --group mem` → the 17 wave-1 mem ids above green.
  - `git -C . diff --stat v0.6.6..hotfix/0.6.7` shows only the three fixes, the stub, the yaml require and tests.

### Wave 2: split (M1 + M2) and Symfony start

- **core + wp (joint, batched):** `git mv` the 131 keep files and the core halves of the 17 splits to `packages/ddd-core/src/`; the 18 moves and WP halves to `packages/ddd-wp/{src,wordpress}/`; R2 subclasses (`TransactionMiddleware`, `IntegrationListener`); the `ConsumerHandle` and `IntegrationConformance` splits (1.4); `IConsumerIdentity`; `HostDefaults` wiring at ddd-wp init; self-consumer registers in `ConsumerRegistry`; `self/services.yaml` lists handlers explicitly instead of the relative resource dir (B6); `ddd-wordpress/di/tactician.yaml` fixed to real classes and compile-tested (A F-28, B26). The root suite runs after every batch.
- **core + wp: code onto the ports (ruling on blocker #70, option b).** Every split class, `ProcessRunner` included, is moved onto the wave-1 ports, and wp ships a **transitional WP-backed implementation of each port**, registered in `HostDefaults`, that keeps 0.6 behaviour on the 0.6 schema (plus the wave-1 fixes): `WpdbTransactionBoundary`; `WpdbOutboxStore` over the existing `OutboxRepository` (unfenced until schema v8); `WpdbOutboxAdministration`; `WpdbProcessStore` over `ProcessRepository` (ignition by `has_ignition` under the wave-1 named lock; no version fencing); a GET_LOCK `IProcessLock` on the legacy name with the wave-1 fail-closed check; an AS-backed `IWakeupScheduler` (legacy hooks and args, no intent table); an `add_action`-backed `ISubscriptionRegistry` fed by `SubscriptionRegistrar`; `WpdbAuditSink`, `WpActorProvider`, `WpEnvironmentProvider`; the touches `IFactObserver`; `SystemClock`. At the end of wave 2, `ProcessRunner`'s core form compiles without WP symbols and its unit tests run against the mem doubles. Wave 3 replaces the transitional wp implementations with the final ones.
- **wp → packaging handoff:** after wp's last move batch, at the end of wave 2, `ddd-wordpress/self/index.php` becomes packaging's shim.
- **wp:** ABI freeze tests: procedural signature snapshots (B14), golden derived names (B15), historical YAML compile matrix (B9), each consumer's `IDDDConfig` implementation loads unchanged (D F4).
- **symfony:** bundle, service-locator passes, `DbalTransactionBoundary`, `DbalPostgresOutboxStore` (fenced claim via `UPDATE … WHERE id IN (SELECT … FOR UPDATE SKIP LOCKED) RETURNING`), `DbalRelayPauseStore`, relay (`ddd:relay`, submit + accept in one tx), `IntegrationFactMessage` + delivery handler, ledger table, `DddRuntimeReset`, D5 providers (session user, console operator), D2 compile-time subscription map, `schema/postgres`, `ddd:schema:dump`.
- **packaging:** root autoload to `packages/*/src` + `replace`; version-unique loader entry; `ddd-wordpress/self/index.php` shim (after the handoff above); winner autoloader maps both package dirs + `compat/` + fall-through diagnostics; `tests/Compat/core-clean-install.sh`; deptrac rules; loader fixtures of 7.2 except `jetpack-mixed`.
- **conformance** (exact ids; cells marked 2 in section 4):
  - **mem** (adds): `audit.sink-fails`.
  - **wp**: `cmd.commit-atomic`, `cmd.commit-failure`, `cmd.reaction-throws`, `cmd.no-boundary`, `cmd.nested-rejected`, `cmd.guards-without-audit`, `cmd.return-value`, `relay.invalid-acceptance`, `relay.replay-keeps-identity`, `delivery.phase-order`, `delivery.delayed-once`, `audit.sink-fails` (12 ids).
  - **sf**: `cmd.commit-atomic`, `cmd.commit-failure`, `cmd.reaction-throws`, `cmd.no-boundary`, `cmd.nested-rejected`, `cmd.guards-without-audit`, `cmd.return-value`, `relay.crash-after-submit`, `relay.lease-fencing`, `relay.invalid-acceptance`, `relay.pause-holders`, `delivery.double-delivery`, `delivery.subscriber-isolation`, `delivery.phase-order`, `worker.no-leak` (15 ids).
  - pdo: none (pdo-default builds in wave 3).
- Acceptance:
  - `vendor/bin/phpunit` (root) → green.
  - `tests/Compat/core-clean-install.sh` → dependency closure exactly `{tangible/ddd-core, league/tactician, psr/container, psr/log}`, no WP globals after autoload, `examples/plain-php/run.php` exits 0.
  - `cd packages/ddd-core && vendor/bin/phpunit` with a bootstrap that loads only Composer autoload → green (no `wp-stubs.php`), including `ProcessRunner` against the mem doubles.
  - `vendor/bin/deptrac analyse` → 0 violations.
  - `tests/harness/run.sh loader` → all 7.2 cases except `jetpack-mixed` green.
  - `tests/harness/run.sh conformance-wp` → the 12 wave-2 wp ids green; `--group mem` → the 18 mem ids of waves 1-2 green.
  - `cd packages/ddd-symfony && vendor/bin/phpunit -c phpunit.integration.xml` on `postgres:16` → the 15 wave-2 sf ids above green.
- **TXP tenancy-reference can start at the end of wave 2.** Its library scope: D11 (return values), D5 (session user + console operator actors), D2 (marker subscription, local and integration), the transaction boundary, Postgres outbox, relay to Messenger, envelope-unwrapping delivery, worker reset. D13's `current_fact()` ships in wave 1 and is usable but not required. It uses no process, lock or alarm machinery: those ports exist from wave 1, but their sf adapters land in wave 3.

### Wave 3: durable contracts on all three hosts (M3; replaces M3a)

- **core:** the ports already exist (wave 1). Legacy bridges (`LegacyOutboxStore`, `LegacyProcessStore`); `ProcessRunner` refactor behind the ports with re-read under the lock, await before dispatch, one-transaction state changes, `ResumeRetry` on contention, fenced version touch before each step dispatch, ignition through `insertIgnited` / `ignition_key` (X7), stranded scan; the extraction variants of the three bug fixes replace the wave-1 schema-free variants; `Drain::runOnce`; `IOperatorView`. Core temporarily owns `tests/Unit/Process/**` for the ProcessRunner test migration and hands it back to wp at the end of the wave.
- **wp:** schema v8 (additive only, R5): `{prefix}_ddd_wakeups`; `long_processes.ignition_key` (nullable, `UNIQUE (process_class, ignition_key)`), backfilled in id order from ignition-path rows only, duplicates reported and never deleted; `long_processes.quarantine_reason` (nullable); `{prefix}_ddd_delivery_ledger`; `long_processes.version`; outbox `claim_token` (the claim also sets `locked_until`/`locked_by`; status stays `completed`); pause rows (reading the option until drained, C25); intent rows backfilled from pending AS actions. Final port implementations replace the wave-2 transitional ones: intents projected to AS at schedule time (3.6); stranded scan with the `as_has_scheduled_action` check (5.3); `insertIgnited` with the `ignited_by_event_id` check inside the ignition lock; GET_LOCK adapter with both names; per-callback invoker wrapping of DDD-registered callbacks; `{prefix}_ddd_redeliver` hook; `wp ddd relay --once`, `wp ddd ops`, `wp ddd drain --before-rollback`.
- **pdo-default:** `PdoConnection` (`PARAM_INT` binding, `isDuplicateKey` on 1062 only), `PdoTransactionBoundary`, `PdoOutboxStore`, `PdoOutboxAdministration`, `PdoPauseStore`, `PdoProcessStore` (with `ignition_key` and `quarantine_reason`), `PdoJobStore` (delivery + wakeups), `PdoDeliveryLedger`, `MySqlNamedLock`, `PdoOperatorView`, `SchemaCheck`, `DurableRuntime::compose()` (frozen signature, 3.3; composes through `SubscriptionRegistrar`); `schema/mysql8/*.sql`; `examples/plain-php-durable/{produce,drain}.php` as the acceptance fixture of `compose()`, using `EnvOffsetClock` (`DDD_CLOCK_OFFSET`).
- **symfony:** `PostgresAdvisoryProcessLock` (section 5.2), `DbalProcessStore` with `ON CONFLICT DO NOTHING RETURNING id`, `ignition_key`, `quarantine_reason` and `version`, `process_waits`, `ddd_wakeups` + transport, `ProcessRunner::start()` persist + `Continue` intent with the `ddd.process.inband_start` opt-in, `IRelayWakeup` via transactional `NOTIFY` and `LISTEN`, pooler warning, `DbalOutboxAdministration`, `ddd:ops:*`; for D10 (ruling #78): `DbalBehaviourWorkflowRepository`, `DbalWorkItemRepository` and a workflow ignition ledger table with a unique dedup key (wired to the core D10 contract in wave 4).
- **conformance** (exact ids; cells marked 3 in section 4):
  - **mem** (adds): `relay.replay-keeps-identity.process`, `delivery.double-delivery.process`, `delivery.subscriber-isolation.process`, `delivery.phase-order.process`, `lock.contention`, `lock.acquire-error`, `lock.reentrant-balance`, `process.ignition-race`, `process.manual-start-in-drain`, `process.timeout-vs-event`, `process.await-before-dispatch`, `process.intent-survives-queue-failure`, `process.stale-wakeup` (13 ids).
  - **pdo** (all new): `cmd.commit-atomic`, `cmd.commit-failure`, `cmd.reaction-throws`, `cmd.no-boundary`, `cmd.nested-rejected`, `cmd.guards-without-audit`, `cmd.return-value`, `relay.fresh-process-pickup`, `relay.crash-after-commit`, `relay.crash-after-submit`, `relay.lease-fencing`, `relay.invalid-acceptance`, `relay.pause-holders`, `relay.replay-keeps-identity`, `relay.replay-keeps-identity.process`, `delivery.double-delivery`, `delivery.double-delivery.process`, `delivery.subscriber-isolation`, `delivery.subscriber-isolation.process`, `delivery.phase-order`, `delivery.phase-order.process`, `delivery.delayed-once`, `lock.contention`, `lock.acquire-error`, `lock.reentrant-balance`, `lock.namespace`, `process.ignition-race`, `process.manual-start-in-drain`, `process.await-all-concurrent`, `process.timeout-vs-event`, `process.await-before-dispatch`, `process.intent-survives-queue-failure`, `process.stale-wakeup`, `process.crash-mid-step`, `process.fresh-process-resume`, `worker.no-leak`, `audit.sink-fails` (37 ids; the suite runs twice, the second time with `ATTR_EMULATE_PREPARES = true`).
  - **wp** (adds): `relay.fresh-process-pickup`, `relay.crash-after-commit`, `relay.crash-after-submit`, `relay.lease-fencing`, `relay.pause-holders`, `relay.replay-keeps-identity.process`, `delivery.double-delivery`, `delivery.double-delivery.process`, `delivery.subscriber-isolation`, `delivery.subscriber-isolation.process`, `delivery.phase-order.process`, `lock.contention`, `lock.acquire-error`, `lock.reentrant-balance`, `process.ignition-race`, `process.manual-start-in-drain`, `process.await-all-concurrent`, `process.timeout-vs-event`, `process.await-before-dispatch`, `process.intent-survives-queue-failure`, `process.stale-wakeup`, `process.crash-mid-step`, `process.fresh-process-resume`, `worker.no-leak` (24 ids; `lock.namespace` is `-` on wp).
  - **sf** (adds): `relay.fresh-process-pickup`, `relay.crash-after-commit`, `relay.replay-keeps-identity`, `relay.replay-keeps-identity.process`, `delivery.double-delivery.process`, `delivery.subscriber-isolation.process`, `delivery.phase-order.process`, `delivery.delayed-once`, `lock.contention`, `lock.acquire-error`, `lock.reentrant-balance`, `lock.namespace`, `process.ignition-race`, `process.manual-start-in-drain`, `process.await-all-concurrent`, `process.timeout-vs-event`, `process.await-before-dispatch`, `process.intent-survives-queue-failure`, `process.stale-wakeup`, `process.crash-mid-step`, `process.fresh-process-resume`, `process.start-from-web`, `audit.sink-fails` (23 ids).
- Acceptance:
  - `tests/harness/run.sh core-pdo` → `packages/ddd-core: vendor/bin/phpunit --testsuite pdo` green on MySQL 8.0 (8.4 optional, non-gating), then the two-process script: `php produce.php && DDD_CLOCK_OFFSET=<past the timeout> php drain.php && DDD_CLOCK_OFFSET=<past the timeout> php drain.php` ends with the process `completed` and the stale timeout a no-op.
  - `tests/harness/run.sh wp-integration` and `run.sh conformance-wp` → green, including the 24 wave-3 wp ids.
  - `cd packages/ddd-symfony && vendor/bin/phpunit -c phpunit.integration.xml --group conformance` → green on Postgres 16, run **without** a per-test transaction wrapper (E section 5).
  - Every cell marked 1, 2 or 3 in section 4 passes on its host.

### Wave 4: TXP process demands, compatibility closure, release preparation

- **core:** D1 (`EffectMiddleware` over the wave-1 `IExternalEffectCommand` / `IEffectJournal` ports, `invalidate` repair path, ledger-counted budget with `failureCommand()` fired by the core invoker, step retry policy for D1 inside steps), D3 (`AwaitAny`, keyed awaits, dynamic `AwaitAll`, precheck), D6 (`LargeString`, cap, quarantine), D7 long alarms, D8 redaction extension, D10 workflow ignition with a dedup key, D12 `IAuditPolicy`, D13 deterministic ids.
- **symfony:** DBAL effect journal (with `invalidate`; no failure-command trigger of its own, since the core invoker fires it), D10 wiring of the wave-3 workflow stores and ignition ledger, `process_waits` any-of, D9 merged view including the Messenger failure transport, the E section 10 reference scenario as a kernel test.
- **wp:** rollback fixtures (7.3, including the N-only artifacts and the three sequence fixtures), `load.jetpack-mixed`, compiled-container resolution for all three shipped zips.
- **pdo-default:** workflow and work-item stores (O8), operator repairs.
- **packaging:** splitsh workflow (`workflow_dispatch` only, no secrets), CHANGELOG, artifact check (`git archive HEAD | tar t` lists no tests, docs, tools, ddd-symfony or ddd-conformance).
- **conformance** (exact ids; cells marked 4 in section 4):
  - **mem**: `process.alarm-long`, `process.await-keyed-precheck`, `process.await-any-cancellation`, `process.await-all-dynamic`, `workflow.fact-ignition-once`, `codec.large-payload`, `decode.unknown-class`, `effect.journal-reuse` (8 ids).
  - **pdo**: `process.alarm-long`, `process.await-keyed-precheck`, `process.await-any-cancellation`, `process.await-all-dynamic`, `codec.large-payload`, `decode.unknown-class`, `effect.journal-reuse` (7 ids).
  - **wp**: `process.alarm-long`, `codec.large-payload`, `decode.unknown-class` (3 ids).
  - **sf**: `process.alarm-long`, `process.await-keyed-precheck`, `process.await-any-cancellation`, `process.await-all-dynamic`, `workflow.fact-ignition-once`, `codec.large-payload`, `decode.unknown-class`, `effect.journal-reuse`, `wakeup.post-commit` (9 ids).
  - The three `process.await-*` ids are D3 (CR-W4C4-1, added to this list in wave 5).
- Acceptance: full CI matrix green (section 2 gating legs); every non-`-` cell in section 4 passes on its host; `tests/harness/run.sh compat` green for every case in 7.2 and 7.3; nothing tagged or pushed to a mirror.
- **TXP process-kernel starts at the end of wave 3** for D4 and D14 (lock and wakeup), and needs wave 4 for D1, D3, D7, D10 (sf stores from wave 3, core contract in wave 4) and D13's deterministic ids. Its in-scope library items: **D1, D3, D4, D7, D10, D13, D14**. Out of scope for both first slices: D6, D8, D9, D12 (gateway, runtime and identity slices).

Review finding 2 is honoured: symfony starts in wave 2 from the wave-1 contracts, in parallel with the WP split, and never waits on loader work.

Scope fence (rulings): the WP rollback machinery of 7.3 is required but ships in wave 4, after the Symfony path is proven. The TXP slices `tenancy-reference` and then `process-kernel` are the acceptance for ddd-symfony; `billing-accounts` is added only if D1 is not otherwise exercised end to end.

---

## 9. Decisions still open

Each has a recommended default. Ruled 2026-10-01: defaults accepted except O1 and O2 (rejected) and O12 (settled differently); those rows show the ruling.

| # | Decision | Recommended default / ruling |
|---|---|---|
| O1 | Ship the version-unique loader entry (D-2) on the prepared 0.6.7 hotfix branch too? | **Ruled: no.** The loader entry lands on the extraction branch only; the hotfix diff stays the three fixes, the stub, the yaml require and tests. |
| O2 | Add a non-gating MariaDB 10.11 smoke leg for ddd-wp? | **Ruled: no.** The operator chose MySQL only; MariaDB is not claimed and has no test leg of any kind. |
| O3 | Buffer reset timing in `DomainEventsPublishMiddleware` (reset at start only, `:15-18`, A F-17) | Also reset in `finally` on failure, after `CorrelationMiddleware` has read `published()` for the audit row. |
| O4 | Touches indexing (`IFactObserver`) outside WP | Not wired in sf or pdo (`NullFactObserver`); nobody in D1-D14 asks for it. |
| O5 | `RetryDeliveryCommand` on `accepted`/`completed` rows | Refuse unless `force: true`; refuse leased rows always. |
| O6 | `is_unique` cancel scope (C26) | Match on payload signature, as `IOutboxRepository.php:101-111` documents; never cancel leased rows. Changelog entry. |
| O7 | TXP Postgres connection topology | Direct (non-pooled) endpoint for `ddd:relay` and `messenger:consume`; pooled allowed for web. TXP confirms on its hosting. |
| O8 | Behaviour-workflow stores in `Defaults/Pdo` | Wave 4, after processes; D10 is Symfony-first. |
| O9 | D1, D2 and D10 on WordPress | Not in this extraction; core contracts allow a later wp adapter (D2 would need boot-time expansion of markers to concrete hooks). |
| O10 | cred's delay values after bug 3 | Ship the fix; ask cred's owner whether `EndpointAuthRefresh` / `BehaviourWorkflowReschedule` were tuned to the doubled delay, before any release. |
| O11 | `symfony/yaml` to `require` on the hotfix branch | Yes; it is a latent fatal (the self-consumer silently disappears, F-5) with no behaviour change otherwise. |
| O12 | Packagist exposure of `dev-extraction/*` branches | **Ruled:** Packagist indexes only the root `composer.json` (name `tangible/ddd`), so new `packages/*` names are not registered by pushing. WIP branch versions `dev-extraction/*` of `tangible/ddd` are acceptable. Keep pushing, including new package directories. |
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
