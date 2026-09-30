# Wave 0 — F: packaging and test harness

Role: F-packaging-harness. Read-only investigation of `/Users/titustc/tgbl/repos/tangible-ddd`, branch `extraction/ddd-packages` at `598858c` (0.6.6). All `path:line` citations are against that tree unless another root is named. Date: 2026-10-01.

Fixed operator decisions this report works within: no bundled MySQL runtime, worker daemon or migration framework in core; a plain-PHP host supplies its own connection (a `PDO` it already owns), core ships ports, in-memory doubles, at most a thin PDO adapter plus schema SQL, and a `runOnce()`/drain entry point the host schedules. MySQL 8 is tested, MariaDB is not claimed, Postgres is tested through ddd-symfony. Tactician stays. Nothing is published or tagged.

## 1. Baselines measured in this session

| Suite | Where | Runtime | Result |
|---|---|---|---|
| Unit (`phpunit.xml`) | host macOS | PHP 8.3.30 | **628 tests, 2166 assertions, OK**, 9 PHPUnit deprecations |
| Unit | Docker `wordpress:cli-php8.2` | PHP 8.2.34 | 628 tests, OK, same 9 deprecations |
| Unit | Docker `php:8.1-cli` | PHP 8.1.34 | **Does not start**: "This version of PHPUnit requires PHP >= 8.2" |
| Integration (`phpunit.integration.xml`) | ddev `anything`, `wp-content/plugins/tangible-ddd` (same SHA 598858c) | PHP 8.3.23, **MariaDB 10.11.13**, WP 7.1.2, `.reference/tangible-datastream` @ `4294d88` | **24 tests, 98 assertions, OK** (0.41 s) |
| Integration | Docker, no ddev: `mysql:8.0` + `wordpress:cli-php8.2`, fresh WP 7.1.2 install into `db_test` with prefix `wptests_`, source repo bind-mounted read-only | PHP 8.2.34, **MySQL 8.0.46**, WP 7.1.2, `.reference/tangible-datastream` @ `04418d5` | **24 tests, 98 assertions, OK** (0.32 s), with warnings listed in F-11/F-12 |

The Docker trial used only throwaway containers and a scratch WP tree; nothing in either repo was written. The exact commands are reproduced in section 5 as the seed of the harness.

Integration test inventory (24 tests in 9 files): `tests/Integration/Framework/{OutboxPauseTest(2), TraceContinuityE2ETest(1), BehaviourWorkflowMetaTableTest(8), CommandBusE2ETest(1), EventSealE2ETest(2), BehaviourWorkflowForkTest(2), OutboxPrimitiveTest(6), NoNestingE2ETest(1)}`, `tests/Integration/Persistence/WorkItemRepositoryCancelledTest(1)`. That is thin for the M3 gate: none of them covers two-worker claims, lock acquisition failure, ignition races, or delayed delivery, i.e. none of the three bugs being fixed.

## 2. Findings

Columns: claim | evidence | owner | compatibility risk | test that proves it | unresolved question.

| # | Claim | Evidence | Owner | Compat risk | Proving test | Unresolved |
|---|---|---|---|---|---|---|
| F-1 | The PHP floor is declared as 8.1 but the locked graph needs 8.2. Symfony DI/config/filesystem/var-exporter 7.4 need >=8.2, and so do PHPUnit 11 and webmozart/assert (dev). The lock's `platform` is copied from the manifest, so Composer never flags it. | `composer.json:13` (`"php": ">=8.1"`), `tangible-ddd.php:10` (`Requires PHP: 8.1`), `composer.lock:3109-3111`; lock entries symfony/config v7.4.4, dependency-injection v7.4.5, filesystem v7.4.0, var-exporter v7.4.0 all `php >=8.2`; the run on php:8.1-cli refuses to start | legacy + every package | Low. An 8.1 site already fatals on Symfony 7.4, so raising the number states what is already required. | CI matrix job on PHP 8.2 (floor) and 8.4 (latest), plus `composer check-platform-reqs --lock` in each package | Should ddd-core alone claim 8.1? It could if its runtime graph drops Symfony (tactician is >=7.2, psr/container >=7.4). 8.1 has been EOL since 2025-12, so I recommend 8.2 across the board. |
| F-2 | Consumers resolve `tangible/ddd` as a Composer **VCS repository on this repo's root**. So the root `composer.json` must stay `tangible/ddd` at every tag, and a sub-package in `packages/` cannot be consumed that way (a VCS repo reads the repo root only). | `../tangible-cred/composer.json:2-6` (`"type":"vcs","url":"git@github.com:TangibleInc/tangible-ddd.git"`), `../tangible-cred/composer.json:13` (`"tangible/ddd": "^0.6.3"`); `composer.json:2` | packaging | High if the root is renamed or turned into a metapackage: every VCS consumer's `composer update` breaks. | Fixture: a scratch consumer with the same VCS repo entry pointing at a local clone (`file://`) resolves `tangible/ddd` at the extraction branch and gets a working plugin | none |
| F-3 | Composer autoload of the current package **boots the runtime**. The `files` entry runs the loader. Outside WP (no `add_action`) it immediately registers a *prepended* spl autoloader and `require_once`s 14 procedural WP files. That breaks the spec's rule that core autoload never registers hooks, negotiates versions or installs a preferred autoloader, so ddd-core must not inherit it. | `composer.json:32-34`; `tangible-ddd.php:396-398` (initialize when there are no hooks), `:275-297` (prepend autoloader), `:307-334` (procedural list, including `ddd-wordpress/db.php`, `locking.php`, `cli/register.php`) | core (must not have it), legacy (keeps it) | None for WP consumers if the loader stays in the legacy root | Clean core install test (section 4): after `require vendor/autoload.php`, assert `!function_exists('TangibleDDD\WordPress\get_lock')` (or whatever `locking.php` defines), `!class_exists('Tangible_DDD_Versions', false)`, and that `spl_autoload_functions()` contains only Composer's loader | none |
| F-4 | `ddd-wordpress/` classes are **not PSR-4 resolvable**. Composer maps only `TangibleDDD\` to `ddd-src/`, so `TangibleDDD\WordPress\Admin\Dashboard\*` resolves only through the loader's own autoloader. Directory and namespace case also disagree (`cli/` vs `CLI`, `self/` vs `SelfConsumer`); those files work only through explicit `require_once`. On Linux this becomes a hard failure once anything relies on PSR-4. | `composer.json:29-31`; `tangible-ddd.php:278-284`; `ddd-wordpress/cli/register.php:8,14`; `ddd-wordpress/self/index.php:15,23`; `ddd-wordpress/cli/class-ddd-command.php` namespace `TangibleDDD\WordPress\CLI` | wp | Medium. Moving files changes `__DIR__`-relative YAML and template paths. | ddd-wp package autoload test on a case-sensitive FS (Linux container): `class_exists` on every class found by a `ddd-wordpress/**` scan, without the legacy loader | Rename the dirs to PSR-4 (`git mv`) or keep a `classmap` for the odd dirs? I recommend a classmap for `cli/` and `self/` in wave 1 (no path churn) and PSR-4 for `Admin/`. |
| F-5 | **`symfony/yaml` is a runtime dependency declared only as dev.** The self-consumer boot builds a `YamlFileLoader` container. On an install that does not bring yaml, it throws `Error: class not found`, which the loader's `catch (\Throwable)` swallows into `error_log`. The framework's own replay/discard/retry/purge commands then silently do not exist. Today it works only because consumers happen to require yaml themselves. | `ddd-wordpress/self/index.php:19,36`; `composer.json:24` (require-dev); `tangible-ddd.php:371-383`; `../tangible-cred/composer.json:17`, `../lms-monorepo/composer.json:26` both require symfony/yaml themselves; the CLI scaffolder also emits `YamlFileLoader` (`ddd-wordpress/cli/class-ddd-command.php:339,351`) | legacy / wp | Low, since adding a require only makes explicit what consumers already install | WP fixture plugin bundling `tangible/ddd` installed `--no-dev` with no other consumer: assert `\TangibleDDD\WordPress\SelfConsumer\di()` returns a compiled container and no `[tangible-ddd] self-consume boot failed` line is logged | Fix on the 0.6.x hotfix branch too, or only on extraction? It is an adjacent latent bug, not one of the three. |
| F-6 | The third-party closure splits cleanly by package. Symfony DI/config is used by 2 files in `ddd-src` (compiler passes) plus the WP boot. makinacorpus/query-builder is used by **one** file. Action Scheduler is used only from WP-side services and hooks. Tactician appears in 7 `ddd-src` files. `psr/log` is not used anywhere. | `ddd-src/Infra/DependencyInjection/{DDDCompilerPasses,LongProcessCatalogPass}.php`; `ddd-src/Infra/Persistence/Select/QueryBuilderSelect.php:5`; `ddd-src/Infra/Services/ActionSchedulerOutboxPublisher.php`, `ddd-wordpress/hooks.php:199-205`; `grep -rl 'League\\Tactician' ddd-src` = 7; `grep Psr\\Log ddd-src` = 0 | core: tactician, psr/container. wp: action-scheduler, symfony DI/config/yaml, query-builder. symfony: DBAL, Messenger, DI | Low | `composer why symfony/dependency-injection` in the core clean-install fixture prints nothing. A deptrac or static check fails if `ddd-core/src` imports `Symfony\`, `ActionScheduler`, `MakinaCorpus\` or any WP function | Where do the two Symfony-DI compiler passes live? Both wp and symfony need them. Options: (a) an optional bridge namespace inside core with symfony/DI as `suggest` and require-dev; (b) duplicate them; (c) a fourth tiny package. I recommend (a), fenced in deptrac as a non-portable layer. |
| F-7 | WP coupling inside `ddd-src` is large: 21 of 166 files call WP functions or `$wpdb` directly, including core-looking ones (`TransactionMiddleware`, `ProcessRunner`, the four outbox repair handlers, `OutboxConfig`, `IDomainEventDispatcher`). The unit suite hides this because `tests/bootstrap.php` always loads 53 WP stub functions. | `grep -rlE '\$wpdb\|get_option\|add_action\|do_action\|apply_filters\|wp_*\|as_*('` over `ddd-src` = 21 files; `tests/bootstrap.php:13`; `tests/wp-stubs.php` (313 lines, 53 functions) | core / wp (disposition belongs to other wave-0 roles) | n/a | Core suite bootstrap **without** `wp-stubs.php`: every test in `packages/ddd-core/tests` must pass with `function_exists('add_action') === false` | Which unit tests move to ddd-wp because they need stubs? This needs a per-test inventory in wave 1. |
| F-8 | **CI is effectively unit-only on PHP 8.2 and does not run on this branch.** `phpunit.yml` triggers on `master`, `v3-ddd`, `release/**`. `phpunit.xml` in `.github/workflows/` is dead: GitHub runs only `.yml`/`.yaml` files, and its content is a copy from another project (`ttt-config.php`, `wordpress-develop` trunk, branches `trunk`/`main`). No integration job, no DB, no PHP matrix, no phpstan. | `.github/workflows/phpunit.yml:5-7,20,27`; `.github/workflows/phpunit.xml:1-80` (file extension); `gh api .../actions/workflows` lists only `phpunit.yml` as active; recent runs 19-23 s, unit only | packaging | None | New workflow runs on `extraction/**` pushes and PRs; a required status check on each job in section 5 | Delete the dead `phpunit.xml` workflow in wave 1? Keep it until the operator agrees. |
| F-9 | `phpstan-deadcode.neon` is not CI-able. It analyses sibling checkouts (`../tangible-cred`, `../tangible-datastream`, `../lms-monorepo`) and scans their vendors. No level-N phpstan config exists for the package itself. | `phpstan-deadcode.neon:21-33`; `composer.json:50` | packaging | None | Per-package `phpstan.neon` at level 5+ with `phpVersion: 80200`, run in CI; keep the deadcode sweep as a manual local tool | Which level? Pick the highest that passes on ddd-core at the start of wave 1, then ratchet. |
| F-10 | The integration harness is **not hermetic**. It hard-codes `ABSPATH='/var/www/html/'` and a ddev DB host, needs a WP already installed with prefix `wptests_`, and needs `.reference/tangible-datastream`. That clone is gitignored and taken from an unpinned `master` over SSH, and it silently skips when SSH is unavailable. The two copies I ran differ (`04418d5` in the source repo, `4294d88` in ddev `anything`), so "same SHA" does not mean the same host under test. | `tests/Integration/bootstrap.php:25-33,49-57`; `composer.json:43-49`; `.gitignore` (`.reference/`); `git -C .reference/tangible-datastream log -1` = 04418d5 vs `anything/.../.reference/tangible-datastream` = 4294d88 | packaging / wp | None | Harness from section 5 pins the datastream ref to a SHA recorded in the repo, and `WP_TESTS_ABSPATH` replaces the constant (one-line change in wave 1) | Should the integration host stay the real datastream plugin? I recommend moving to an in-repo fixture consumer (`tests/Fakes/Acme` already exists) for framework tests, and keeping datastream as a separate consumer-compat job. |
| F-11 | On a **fresh** DB, `wptests_tangible_datastream_behaviour_workflows_meta` does not exist when `BehaviourWorkflowForkTest` runs. wpdb logs 5 "table doesn't exist" errors, yet both tests pass. So test outcome depends on DB state (the ddev DB already had the table), and at least one write path ignores wpdb failure. | MySQL 8 Docker run log (5 errors naming `BehaviourWorkflowForkTest::test_partial_failures_produce_child_workflow_with_failed_items` and `test_all_succeed_...`); assertions at `tests/Integration/Framework/BehaviourWorkflowForkTest.php:218-274,344-356`; ddev run shows no such errors | wp (tables and checked writes); packaging (fresh DB per run) | Low | Harness always starts from an empty schema; add a bootstrap assertion that all framework and consumer tables exist before the first test; add an assertion in the fork test that meta was persisted | Which repository write drops the error? Hand to the persistence role (checked DB results, spec M3). |
| F-12 | The bootstrap `define`s DB constants and then loads a `wp-config.php` that defines them again. That gives 4 "Constant already defined" warnings on PHP 8.2. They are harmless today, but an `error_reporting`/`failOnWarning` tightening would turn them into failures. | `tests/Integration/bootstrap.php:25-28` then `:36`; Docker run log lines "Constant DB_NAME already defined in /var/www/html/wp-config.php" | packaging | None | Harness generates a `wp-config.php` that reads `getenv()` guarded by `defined()` | none |
| F-13 | The loader's autoloader **falls through silently** on a missing file. When a new winner has moved or removed a `TangibleDDD\` class, the class then loads from an older copy's Composer PSR-4 map: exactly the "undefined class mixture" the spec forbids. The version registry picks one winner path but does not make it authoritative for the namespace. | `tangible-ddd.php:281-292` (`if (file_exists($file)) require_once; return;`, no else); `tangible-ddd.php:295-296` (prepend, so later loaders still run) | legacy | **High**: this is where the split meets mixed installs. Old 0.6.x consumers keep `TangibleDDD\ => vendor/tangible/ddd/ddd-src/` in their own Composer maps. | Loader fixture L4/L5 (section 6): after the new copy wins, `ReflectionClass::getFileName()` of every probed `TangibleDDD\` class starts with the winner path. A deliberately moved class resolves to the winner's alias or throws a named error, never to the old copy. | Is an unowned-class throw acceptable at runtime, or only a `class_alias` shim table? I recommend an alias map for moved names plus a logged hard error for unknown names under the winner's prefixes. |
| F-14 | Loader identity is already guarded by a unit test (four version sites move in lockstep), and registry semantics by `TangibleDDDVersionsTest`. Neither exercises **two real vendor trees** or real WP plugin load order. | `tests/Unit/Loader/LoaderIdentityTest.php:53-105`; `tests/Unit/Loader/TangibleDDDVersionsTest.php` | legacy | n/a | Loader fixtures L1-L8 (section 6) | none |
| F-15 | Dist archives would ship tests, docs, tools and the mega-trace sidecar. No `.gitattributes` `export-ignore` exists, so every consumer vendoring `tangible/ddd` through a VCS dist zip gets `tests/Fakes`, `tools/mega-trace`, `docs/` and (after the split) `packages/ddd-symfony`. | `ls .gitattributes` → missing; top-level `tests/`, `tools/`, `docs/` | packaging | Low. Consumers with `--prefer-source` see no change. | `git archive HEAD \| tar t` lists no `tests/`, `tools/`, `docs/`, `packages/ddd-symfony/` | none |
| F-16 | A stray untracked file sits in the ddev copy (`ddd-src/Application/Correlation/what is a suzani jacket - Google Search.html`). It is not in the source repo, but it proves that an "identical copy" can pick up untracked files, and the harness should run against a clean export. | `git -C /Users/titustc/tgbl/anything/wp-content/plugins/tangible-ddd status --short` | packaging | None | Harness mounts a `git worktree` or `git archive` of the pinned SHA, never a working plugin dir | none |

## 3. Package build and publish design

### Decision: one repo, **root stays the legacy `tangible/ddd` WordPress distribution**, sub-packages under `packages/`, path repositories for development, and a subtree split (splitsh-lite) as the publish mechanism, designed now and not run until the operator says so. No build step.

Layout (target of wave 1-2; source moves by `git mv`, so history survives the split):

```text
tangible-ddd/                      # composer name: tangible/ddd  (legacy + WP distribution, unchanged VCS URL)
├── composer.json                  # autoload psr-4 → packages/ddd-core/src + packages/ddd-wp/src ; files → tangible-ddd.php
│                                  # "replace": {"tangible/ddd-core": "self.version", "tangible/ddd-wp": "self.version"}
├── tangible-ddd.php               # the newest-wins loader (maps to packages/*/src of the WINNING copy)
├── compat/                        # class_alias map for moved names (legacy-owned, spec §5)
├── packages/
│   ├── ddd-core/   composer.json  # tangible/ddd-core: php>=8.2, league/tactician, psr/container; suggest ext-pdo
│   │   ├── src/{Domain,Application,Runtime,Testing,Defaults/Pdo}/
│   │   ├── schema/{mysql,postgres}/*.sql   # plain DDL files, host applies them; no migrator
│   │   └── tests/{Unit,Conformance,Pdo}/
│   ├── ddd-wp/     composer.json  # tangible/ddd-wp: requires ddd-core, action-scheduler, symfony DI/config/yaml, query-builder
│   └── ddd-symfony/ composer.json # tangible/ddd-symfony: requires ddd-core, doctrine/dbal, symfony/messenger, framework-bundle
├── tests/{Integration,Loader,Compat}/   # WP-level and cross-package fixtures (root-only)
└── .gitattributes                 # export-ignore tests/ tools/ docs/ packages/ddd-symfony/ packages/*/tests/
```

Why this and not the alternatives:

| Option | Keeps VCS consumers working (F-2) | Matched core + adapter in each WP copy (spec §5) | Independent core/symfony installs | Moving parts | Verdict |
|---|---|---|---|---|---|
| **Root = fat legacy distribution with in-repo PSR-4 to `packages/*/src` + `replace`; splitsh for sub-packages** | Yes. Root name, URL and tags are unchanged. | Yes, by construction: one dist zip carries core+wp from the same commit, and the loader resolves both from the winner's own dir | Yes, from split mirrors (or path repos before publishing) | One GH Action on tag (disabled until approved) | **Chosen** |
| Root = metapackage requiring ddd-core + ddd-wp | Name is kept, but files move into `vendor/tangible/ddd-core` etc. | **No.** The loader in `vendor/tangible/ddd` has to find a sibling package whose install path depends on each consumer's vendor-dir; a mixed site can pair copy A's loader with copy B's core. The spec already rejects a bare metapackage (spec §5). | Yes | Few | Rejected |
| Build step assembling a legacy artifact into a dist branch or a second repo | Only if consumers are re-pointed to the artifact repo, which the operator ruled out ("no consumer dependency changes") | Yes | Yes | Build + artifact repo + tag sync | Rejected: it changes consumer manifests |
| Path repositories only | n/a (local only) | n/a | Local only | None | **Used for dev and for TXP pre-publish**, not as a publish mechanism |

Rules that make it hold:

1. `replace` means a project can never get both `tangible/ddd` and a separately split `tangible/ddd-core` in one vendor tree. Composer rejects the combination and TXP simply never requires `tangible/ddd`.
2. ddd-symfony is **not** in root autoload or `replace`, and root `require` has no Symfony-bundle or DBAL deps. A WP consumer's graph therefore does not grow.
3. Each `packages/*/composer.json` requires siblings as `"tangible/ddd-core": "self.version"` or `^0.7@dev`. Each package's own CI resolves siblings through `"repositories": [{"type":"path","url":"../ddd-core","options":{"symlink":false}}]`. `symlink:false` copies the files, so tests catch files that exist only through the monorepo.
4. TXP (Symfony) consumes before publishing via a path repository to a local checkout of `packages/ddd-symfony` and `packages/ddd-core`. That needs no tag, no Packagist and no consumer change, so it fits "nothing is published".
5. Publishing, when approved: `splitsh-lite --prefix=packages/ddd-core` pushes to read-only mirrors `TangibleInc/ddd-core` and `TangibleInc/ddd-symfony` (and `ddd-wp` only if F-open-3 says so). Tags are mirrored as-is, so all packages share one version line (0.7.0 everywhere). `git subtree split` also works; splitsh is just faster and deterministic.
6. The loader keeps its four lockstep version sites (`LoaderIdentityTest`) and gains the authoritative-namespace rule from F-13. `LoaderIdentityTest` extends to assert that `packages/ddd-core/composer.json`, `packages/ddd-wp/composer.json` and the plugin header all carry the same version.

### Where the plain-PHP "raw port" lives (operator decision applied)

The standalone profile is `packages/ddd-core/src/Defaults/Pdo`. It is a thin adapter set whose constructors take the **host's** `\PDO`, e.g. `new PdoOutboxRepository($pdo, new TableNames('acme'))`. There is no DSN parsing, credential lookup, connection factory, migrator or daemon. The pieces are:

- `schema/mysql/*.sql`: plain `CREATE TABLE IF NOT EXISTS` files the host runs however it likes (CodeIgniter migration, `mysql <`, a deploy script). A `SchemaCheck::assert($pdo)` helper throws a clear error when a table is missing (spec: "missing schema must fail clearly").
- `Runtime\Drain::runOnce(int $maxItems, int $maxSeconds): DrainReport`: relays due outbox rows and due continuations or timeouts once, then returns. The host calls it from cron or at the end of a request, with no loop or signals. A long-running worker is the host's `while (true) { $drain->runOnce(...); sleep(1); }` if it wants one.
- `ext-pdo` is a `suggest`, not a `require`. `ext-pdo_mysql` is the host's concern.
- `Pdo` is fenced in deptrac. Portable policy never imports it, which addresses review finding 4 without a MySQL runtime in core.

The same `Defaults/Pdo` classes can be pointed at Postgres, but only ddd-symfony (DBAL) claims Postgres. The PDO adapter is tested on MySQL 8 only, so its README states "MySQL 8 tested; other drivers untested".

## 4. Clean core install test

This lives at `tests/Compat/core-clean-install.sh` (root), runs in CI in a bare `php:8.2-cli` container with Composer, and has no WP, no Symfony and no DB.

1. `mktemp -d`, write a `composer.json` with `"repositories":[{"type":"path","url":"<repo>/packages/ddd-core","options":{"symlink":false}}]` and `"require":{"tangible/ddd-core":"@dev"}`, then `composer install --no-dev`.
2. Dependency-closure assertions: `composer show --name-only` equals the allow-list `{tangible/ddd-core, league/tactician, psr/container}` (plus any explicitly approved addition), and `composer why woocommerce/action-scheduler`, `symfony/dependency-injection` and `makinacorpus/query-builder` all return nothing.
3. Run `examples/plain-php/run.php`, which composes a handler map with an in-memory outbox and an in-memory clock, dispatches a command, reads a query result, observes a domain reaction, checks that a thrown handler propagates, and checks that a `ITransactionalCommand` with no transaction backend fails **before** the handler runs.
4. Global-state assertions after `require 'vendor/autoload.php'` and before any composition: `!function_exists('add_action')`, `!class_exists('Tangible_DDD_Versions', false)`, `!defined('TANGIBLE_DDD_VERSION')`, and `count(spl_autoload_functions()) === 1` (Composer only). After running the example, no declared class name matches `/WordPress|ActionScheduler|Symfony\\Component\\DependencyInjection/`.
5. Optional PDO leg (separate job, needs the MySQL service): the host creates `new PDO('mysql:...')`, applies `schema/mysql/*.sql`, commits an event in process A (`php produce.php`), then process B (`php drain.php`) calls `runOnce()` and delivers it. This is the spec's two-process acceptance, shrunk to the fixed decision (no worker binary).

## 5. Real-database matrix with Docker, no ddev

I proved it in this session (section 1). It becomes `tests/harness/docker-compose.yml` plus `tests/harness/run.sh`, and the same compose file backs a GH Actions job (`services:` or `docker compose up`).

Services:

| Service | Image (pinned by digest in the repo) | Used by |
|---|---|---|
| `mysql8` | `mysql:8.0` (8.0.46 measured); add `mysql:8.4` as a second leg once 8.0 is green | WP integration (legacy/wp), `Defaults/Pdo` suite (core) |
| `pg16` | `postgres:16` (already present locally) | ddd-symfony DBAL integration and conformance |
| `php` | `wordpress:cli-php8.2` for WP jobs (has mysqli + wp-cli); `php:8.2-cli` + `pdo_mysql`, `pdo_pgsql` for core and symfony jobs; the matrix adds `8.4` | test runner |

Job matrix (each job starts from an **empty** database volume, which addresses F-11):

| Job | PHP | DB | Command | Gate |
|---|---|---|---|---|
| core-unit | 8.2, 8.4 | none | `packages/ddd-core: vendor/bin/phpunit` (bootstrap without wp-stubs) | M1 |
| core-clean-install | 8.2 | none | `tests/Compat/core-clean-install.sh` | M1 |
| core-pdo | 8.2 | mysql8 | `phpunit --testsuite pdo` + two-process drain script | M3 |
| wp-unit (legacy suite) | 8.2, 8.4 | none | root `vendor/bin/phpunit` (today's 628) | every PR |
| wp-integration | 8.2 | mysql8 | WP 7.1.2 install then `phpunit -c phpunit.integration.xml` | every PR |
| wp-loader-fixtures | 8.2 | mysql8 | section 6 | M2 |
| symfony-integration | 8.2, 8.4 | pg16 | `packages/ddd-symfony: phpunit -c phpunit.integration.xml` | M4 |
| static | 8.2 | none | phpstan per package + deptrac (core must not import wp/symfony/Pdo from portable layers) | every PR |

WP job steps, as run today (throwaway, verified green):

```sh
docker network create ddd-h
docker run -d --name ddd-h-mysql --network ddd-h -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=db_test -e MYSQL_USER=db -e MYSQL_PASSWORD=db mysql:8.0
# WP core into a scratch dir (wp-cli needs memory_limit raised for the extract)
docker run --rm --network ddd-h -u 33:33 -e HOME=/tmp -v $WP:/var/www/html wordpress:cli-php8.2 sh -c '
  php -d memory_limit=1G /usr/local/bin/wp core download --version=7.1.2 --quiet &&
  wp config create --dbname=db_test --dbuser=db --dbpass=db --dbhost=ddd-h-mysql --dbprefix=wptests_ --skip-check &&
  wp core install --url=http://localhost --title=t --admin_user=a --admin_password=a --admin_email=a@a.test --skip-email'
# plugin mounted READ-ONLY from a clean export of the pinned SHA
docker run --rm --network ddd-h -u 33:33 -e HOME=/tmp -e WP_TESTS_DB_HOST=ddd-h-mysql \
  -v $WP:/var/www/html -v $EXPORT:/var/www/html/wp-content/plugins/tangible-ddd:ro \
  -w /var/www/html/wp-content/plugins/tangible-ddd wordpress:cli-php8.2 \
  php -d memory_limit=1G vendor/bin/phpunit -c phpunit.integration.xml --cache-directory /tmp/pc
```

Wave-1 edits the harness needs (all in test code, none in runtime):
- `tests/Integration/bootstrap.php:33`: `ABSPATH` from `getenv('WP_TESTS_ABSPATH') ?: '/var/www/html/'`.
- `tests/Integration/bootstrap.php:25-28`: keep the env overrides and have the harness write a `wp-config.php` whose `define`s are guarded by `defined()` (F-12).
- Pin `.reference/tangible-datastream` by SHA in a checked-in file (`tests/harness/refs.lock`) that the harness clones over HTTPS with a token, or replace it with an in-repo fixture consumer (F-10).
- Bootstrap asserts that the framework and fixture-consumer tables exist after install (F-11).

## 6. Loader fixture plan

The fixtures live under `tests/Loader/fixtures/`. They run in the wp-loader-fixtures job on the WP install above; each case is a fresh `wp_options.active_plugins` ordering plus a fresh PHP process driven with `wp eval-file`.

Artifacts under test:
- **OLD-0.6.6** is `git archive 598858c` with `composer install --no-dev`, the current monolith.
- **OLD-0.2.x** is the newest 0.2 tag, because `tangible-reporting` pins `^0.2.5` (review finding 1). Include it only if reporting is in the compatibility window.
- **NEW** is the extraction branch root distribution with `composer install --no-dev`.

Each artifact is wrapped in a minimal fixture plugin (`fx-a`, `fx-b`, ...) whose `vendor/` bundles one copy, whose main file `require`s its own `vendor/autoload.php` and calls `Tangible_DDD_Versions::instance()->require_version('fx-a', '<min>')`, and which registers one trivial consumer (command + listener) at `plugins_loaded` 10.

| Case | Setup | Assertions |
|---|---|---|
| L1 | NEW alone | winner = NEW path; one command → domain event → listener round trip works; `wp ddd` command registered; self-consumer `di()` built (F-5) |
| L2 | OLD-0.6.6 alone | today's behaviour (baseline recording) |
| L3 | OLD then NEW (activation order) | winner = NEW; `ReflectionClass(X)->getFileName()` under NEW's path for a probe set covering one core class, one WP class and one moved class |
| L4 | NEW then OLD | same as L3 (order independence) |
| L5 | OLD plugin **uses a `TangibleDDD\` class at include time** (before `plugins_loaded`), then NEW | Either every probed class comes from one copy, or the run fails with a named "incompatible preloaded tangible-ddd class" error. It must never pass silently with mixed copies (spec §5, F-13). |
| L6 | NEW + NEW (same version, two plugins) | one registration; winner = first-registered path; no duplicate hook registration (listener fires once) |
| L7 | theme-style late load (after `plugins_loaded`) | late-load branch initializes (`tangible-ddd.php:403-410`) |
| L8 | consumer requires min > winner | `unmet_minimums()` reports it; admin notice/health surfaced; no fatal |
| L9 (optional) | OLD-0.2.x + NEW | Either it works, or it fails early with a clear version error, as the spec's fallback allows |

Each case also records any `error_log` output and asserts that the `plugins_loaded` sequence produced no PHP warning.

## 7. D1-D14 and packaging

The TXP port demands (module-map D1-D14; rollup decision 6 recommends building them in ddd-symfony) change packaging in one respect. Anything generic (ExternalEffect, keyed await, cancellation/AwaitAll, fact-ignited workflows, handler return values, audit redaction policy, cause/uuid5 access) is **port + policy in ddd-core**, with its SQL/Messenger realisation in ddd-symfony and a wpdb realisation in ddd-wp when WP needs it. Only the transport-shaped demands (post-commit wakeup through Messenger, Postgres advisory-lock choice, long alarms on a Messenger delay stamp) belong solely to ddd-symfony. If D1-D14 are built only inside ddd-symfony, the conformance suite in `packages/ddd-core/tests/Conformance` cannot cover them and WP forks the semantics later. The package layout above allows either choice; the choice itself belongs to the port-design role.

## 8. Proposed wave-1 packaging tasks (ordered)

1. Raise the PHP floor to 8.2 in `composer.json`, the plugin header and `LoaderIdentityTest` (F-1). Add `symfony/yaml` to `require` (F-5; also a hotfix-branch candidate).
2. Add a CI workflow that runs on `extraction/**`: wp-unit on PHP 8.2 and 8.4, plus wp-integration on MySQL 8 through the Docker harness (section 5). Retire the dead `phpunit.xml` workflow once the operator agrees (F-8).
3. Make the harness hermetic: `ABSPATH` from env, a pinned datastream ref or an in-repo fixture consumer, a fresh DB per run, a table-existence assertion (F-10, F-11, F-12).
4. Create `packages/ddd-core/composer.json` (empty src at first), point root autoload at `packages/*/src` with `replace`, and add `.gitattributes` export-ignore (F-15). The unit suite must still show 628 green after each `git mv` batch.
5. Add core-clean-install and deptrac/phpstan jobs (sections 4 and 5).
6. Add loader fixtures L1-L8 before the first class moves out of `ddd-src` (F-13, F-14).
7. Write the splitsh publish workflow, disabled (`workflow_dispatch` only, no secrets configured).

## Open questions

1. **ddd-wp as a separately published package.** Should WP plugins ever require `tangible/ddd-wp` directly, or does `tangible/ddd` remain *the* WP distribution for the whole compatibility window? A standalone ddd-wp without the loader loses newest-wins coexistence. My recommendation: publish ddd-wp only as a layering artifact and document "WP plugins require `tangible/ddd`".
2. **Symfony-DI compiler passes** (`DDDCompilerPasses`, `LongProcessCatalogPass`): an optional bridge namespace in core (recommended), duplicates in wp and symfony, or a fourth micro-package? (F-6)
3. **Loader behaviour for unowned `TangibleDDD\` classes** under a new winner: an alias map only, or an alias map plus a hard error for unknown names? This decides whether L5 is "works" or "fails loudly". (F-13)
4. **Compatibility-window floor**: does the loader fixture matrix include 0.2.x, because tangible-reporting pins `^0.2.5`? If reporting is abandoned, L9 goes away.
5. **Integration host**: keep the real tangible-datastream plugin as the framework's integration host (it needs pinned SSH or token access in CI), or switch the framework suite to an in-repo fixture consumer and run datastream as a separate consumer-compat job?
6. **Split mirrors' ownership and naming** (`TangibleInc/ddd-core`, `TangibleInc/ddd-symfony`) and whether they are private VCS repos or a private Packagist/Satis. This is not needed until publishing, which is currently out of scope.
7. **MySQL 8.4 LTS** as a second leg alongside 8.0 (8.0 reached EOL in 2026-04)? I recommend yes, cheap in the matrix.
8. **Hotfix branch scope**: does the `symfony/yaml` require (F-5) ride on the 0.6.x hotfix branch with the three verified bugs, or only on extraction?
9. **Where the WP fixture-plugin artifacts are built** for the loader matrix: in CI from tags each run (slow, hermetic), or cached tarballs committed under `tests/Loader/fixtures/` (fast, but vendored code in the repo)?
