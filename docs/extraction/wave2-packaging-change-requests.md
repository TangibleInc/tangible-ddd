# Wave 2 round 2: packaging change requests

Author: packaging (branch `wave2/packaging`). Every entry is additive. None changes a ratified interface, a frozen FQCN or a persisted string. The coordinator ratifies after the round.

## CR-PK-1: loader classes `Tangible_DDD_Winner_Autoloader` and `Tangible_DDD_Load_Diagnostics` (new, root `loader/`)

- **What.** The winner's prepended autoloader moves from a closure in `tangible-ddd.php` into `loader/winner-autoloader.php` (global class `Tangible_DDD_Winner_Autoloader`). It maps `TangibleDDD\` to `packages/ddd-core/src` then `packages/ddd-wp/src`, `TangibleDDD\WordPress\` to `packages/ddd-wp/wordpress`, mirrors the root classmap (`CLI\DDD_Command`, `SelfConsumer\HandlerClassNameInflector`), serves `compat/aliases.php` entries as `class_alias`, and reports a fall-through when another copy's Composer loader would serve a name the winner lacks. `loader/load-diagnostics.php` (global class `Tangible_DDD_Load_Diagnostics`) holds the findings (`fall-through`, `mixed-load`, `unsupported-version`, `compat-map`), logs each once through `error_log` with the `[tangible-ddd]` prefix, and runs the boot-time probes from the winner's initializer.
- **Why.** Register 1.5 asks for the alias map and logged fall-throughs; 7.2 `load.preloaded-class` and `load.v0-2-negative` need a detector that runs at winner boot. Global `Tangible_DDD_*` names follow the existing `Tangible_DDD_Versions` convention and stay out of the `TangibleDDD\` prefix the packages own (R1). Only the winner's initializer requires the files, so one definition is live per request.
- **Deviation recorded.** Register X1 and 7.2 say "ddd-wp probes for 0.2-only FQCNs at winner boot". The probe lives in the root loader instead: the root owns the loader and its boot (1.2), and ddd-wp has no hook of its own that runs before the winner initializer. Behaviour is as specified.
- **The "named unsupported-version error".** Interpreted as a finding whose message starts with the error name `TANGIBLE_DDD_UNSUPPORTED_VERSION`, logged always and raised under `WP_DEBUG` as `E_USER_WARNING` (`trigger_error`). It is not an exception: the loader never throws (1.5), and a throw at `plugins_loaded:1` would take the site down. If the coordinator wants an exception class in debug mode, it is a small additive change.
- **Detection.** A registered version below the window floor `0.6.2`, or any of the four 0.2-only FQCNs (`Application\Correlation\CorrelationContext`, `Application\Logging\CommandAuditMiddleware`, `Application\EventHandlers\AsyncWordPressActionHandler`, `Application\Events\TransportEnvelope`; present in v0.2.5 and v0.3.0, absent from every 0.6.x tag, from `hotfix/0.6.7` and from this distribution) that is declared or that some registered Composer loader would serve (`ClassLoader::findFile`, so the probe loads nothing).
- **Tests.** `tests/Unit/Loader/WinnerAutoloaderTest.php`, `LoadDiagnosticsTest.php`; harness cases `load.preloaded-class`, `load.v0-2-negative[*]`.
- **Compatibility.** The registry class, the version-named functions and `tangible_ddd_self_consume` keep their 0.2-era shapes (B3, B4). No new symbol is shared between copies.

## CR-PK-2: `compat/aliases.php` ships empty

- **What.** The root alias map returns `[]` in 0.7.0.
- **Why.** The extraction keeps every FQCN (R1-R5): the 18 moves and 17 splits keep their names, so there is nothing to alias yet. The mechanism is in place and tested with synthetic entries; `WinnerAutoloaderTest` requires every future entry's target to ship in the distribution and its legacy name not to exist as a file.

## CR-PK-3: the root restates `psr/container` as well as `psr/log`

- **What.** Root `require` gains `psr/log: ^1|^2|^3` (wave-1 notes) and `psr/container: ^1.1|^2.0`.
- **Why.** The root `replace`s `tangible/ddd-core`, so Composer never reads core's manifest inside the root graph. `PackageManifestTest` now asserts the root restates every requirement of the core it replaces; `psr/container` was only arriving transitively through `symfony/dependency-injection`.
- **Compatibility.** Runtime lock unchanged (`psr/container` 2.0.2 was already installed); `psr/log` 3.0.2 added.

## CR-PK-4: `packages/ddd-core/composer.json` autoloads `src/Domain/Shared/assert.php`

- **What.** Core's manifest gains `"files": ["src/Domain/Shared/assert.php"]`.
- **Why.** Register 1.5 names it as core's only files entry; the manifest skeleton lacked it, and `core-clean-install.sh` caught the missing `assert_type()` on a core-only install.

## CR-PK-5: transitional allowances, each self-expiring

- **deptrac `skip_violations`.** 15 exact dependencies of core files on the three split-deferred classes still in `packages/ddd-wp/src` (`Application\Commands\Command` and its `SelfConsumer\di()` call, `Application\Infrastructure\InfrastructureEvent`, `Application\Process\ProcessRunner` in `SubscriptionRegistrar`). deptrac errors on a skip that no longer matches, so each entry must be deleted when its class moves; `DeptracConfigTest` forbids skips that do not point at a file in `packages/ddd-wp/src`.
- **`phpstan-core.neon` `scanDirectories: packages/ddd-wp/src`.** Same reason; delete when the round-2 splits land.
- **`core-clean-install.sh` PENDING/SKIP.** Core classes that extend a split-deferred class still in ddd-wp, and a missing `examples/plain-php/run.php`, are reported (PENDING, SKIP) and fail only with `DDD_GATE=1`. The gate run uses `DDD_GATE=1`.

## CR-PK-6: `phpstan-core.neon` (new static check)

- **What.** PHPStan level 0 over `packages/ddd-core/src` with no WordPress, WP-CLI or Action Scheduler symbols available, so any WordPress function, class or constant used in core is reported. Runs in CI next to deptrac.
- **Why.** deptrac drops unqualified function calls inside a namespace (`add_action()` in `namespace TangibleDDD\Foo` cannot be resolved without reflection, `FunctionCallExtractor`), so deptrac alone would miss the most common WordPress coupling. Verified with probe files both ways.

## Requests to other owners

- **wp (B8, `load.legacy-first` INFO line).** `TangibleDDD\Infra\Config::version()` (`packages/ddd-wp/src/Infra/Config.php:55`) and `AdminPage::enqueue()` (`packages/ddd-wp/wordpress/Admin/Dashboard/AdminPage.php:67`) read `TANGIBLE_DDD_VERSION`, which the first-loaded copy defines; with a legacy copy loaded first they report the legacy version while N is the winner. Register 7.2 asks "dashboard version = `winner()`". Suggested: read `Tangible_DDD_Versions::instance()->winner()['version']` when available, the constant otherwise. The loader harness prints this as INFO on every legacy-first case and will make it a pass condition once wp lands it.
- **wp.** The 13 procedural forwarding shims under `ddd-wordpress/` (everything except `self/index.php`) are no longer referenced: the winner loads `packages/ddd-wp/wordpress/*` directly. Register 1.1 says "nothing else under `ddd-wordpress/`". Delete them; `LegacyPathShimsTest` already derives its list from the loader and then checks only the self shim (its docblock still describes the old list).
- **core (examples/plain-php).** `tests/Compat/core-clean-install.sh` runs `examples/plain-php/run.php` from a directory that holds a copy of `examples/plain-php/` next to a clean `vendor/` (or installs the example's own `composer.json` with `tangible/ddd-core` redirected to the export), with `DDD_AUTOLOAD` set to that `vendor/autoload.php`. Please bootstrap with `require getenv('DDD_AUTOLOAD') ?: __DIR__ . '/vendor/autoload.php';` (or ship a `composer.json`), and touch nothing outside ddd-core's closure.
- **core (round-2 splits).** When `Command`, `InfrastructureEvent` and `ProcessRunner` move their core halves to `packages/ddd-core/src`, the deptrac skips and the `phpstan-core.neon` scan directory above become stale; packaging removes them at the gate (deptrac fails on stale skips, so this cannot be forgotten).
