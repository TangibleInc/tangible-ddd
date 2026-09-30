# Wave 2 round 1: split-move change requests

Author: split-move (branch `wave2/split-move`). All entries are additive. None changes a ratified interface's signature. The coordinator ratifies after the round.

## CR-SM-1: `TangibleDDD\WordPress\Adapter\UncheckedWpdbTransactionBoundary` (new class, ddd-wp, `@internal`)

- **What.** An `ITransactionBoundary` over `wpdb` that issues `START TRANSACTION` / `COMMIT` without checking their results, issues `ROLLBACK` on a throw (a rollback error is ignored and the original rethrown), and has no nesting policy.
- **Why.** Register 1.4 says the R2 `TransactionMiddleware(?wpdb)` extends `TransactionalCommandMiddleware` "with `WpdbTransactionBoundary`", and in the same row says the R2 tests pin the legacy behaviour: START/COMMIT unchecked. The port docblock (3.2) says the wp boundary checks every query result. The two cannot be the same class. The legacy subclass therefore gets this private-to-ddd-wp boundary, and the checked `WpdbTransactionBoundary` for new wiring stays round-2 port work.
- **Behaviour pinned by** `tests/Unit/Persistence/TransactionMiddlewareTest.php` (unchecked results, rollback-error suppression, nested START/START/COMMIT/COMMIT as 0.6, `TypeError` from `new TransactionMiddleware()` outside WP, global fallback).
- **Compatibility.** None visible: `TransactionMiddleware`'s constructor and query sequence are unchanged.

## CR-SM-2: `TangibleDDD\WordPress\Testing\WpIntegrationConformance` and `IntegrationConformance::listener_bases()` (new class; new protected static method; `final` removed)

- **What.** The core `TangibleDDD\Testing\IntegrationConformance` is no longer `final` and gains `protected static function listener_bases(): array` (default `[IntegrationTranslator::class]`), consulted through late static binding by `listener_violations()`. The new wp subclass returns `[IntegrationTranslator::class, IntegrationListener::class]`.
- **Why.** Register 1.4 / ruling #57: the core form must target `IntegrationTranslator` and not import `IntegrationListener`, and "a wp subclass keeps the `IntegrationListener` constructor-side-effect check". A subclass needs the class to be non-final and a seam for the exempt bases.
- **Compatibility.** Removing `final` and adding a protected static method is additive. `TangibleDDD\Testing\IntegrationConformance::listener_violations()` returns the same verdicts as 0.6 for WordPress listeners (the inherited `IntegrationListener::__construct()` takes no parameters, so it yields no violation either way); `WpIntegrationConformanceTest` asserts both forms agree on the existing fixtures.

## CR-SM-3: `ConsumerRegistry::add()` parameter widened to `IConsumerIdentity`

- **What.** `add(IDDDConfig $config, ...)` becomes `add(IConsumerIdentity $config, ...)`.
- **Why.** Register 3.1 states it ("`add()` widened to `IConsumerIdentity`"); it is listed here only because the round-1 task text did not name it and ruling #56 names only the `ConsumerHandle` side. Without it an identity-only host (ddd-symfony's `txp`) cannot register.
- **Compatibility.** Parameter widening on a `final` class's static method; every existing caller passes an `IDDDConfig`, which now extends `IConsumerIdentity`. `config_for()` keeps its `IDDDConfig` return and throws `NotAWordPressConsumer` for an identity-only consumer. `add_module()` shares the host's identity.

## CR-SM-4: `Domain/Shared/assert.php` definition guarded by `function_exists`

- **What.** `assert_type()` is defined inside `if (!function_exists(__NAMESPACE__ . '\\assert_type'))`.
- **Why.** Register 1.1/1.5 make the file a Composer `files` entry of the root and of ddd-core, while the winner's loader still `require_once`s its own copy. On a site with two vendored copies the two paths differ and the unguarded file fatals with "Cannot redeclare". `AssertFileAutoloadTest` reproduces the fatal without the guard.
- **Compatibility.** Same function, same signature; first definition wins, as with every other multi-copy symbol in the loader.

## Items that need another owner (not change requests of mine)

- **packaging:** switch the winner's procedural list in `tangible-ddd.php` to `packages/ddd-wp/wordpress/*` and update the `'ddd-wordpress/hooks.php'` / `'ddd-wordpress/modules.php'` literals in `tests/Unit/Loader/LoaderIdentityTest.php`; then delete the 13 procedural shims under `ddd-wordpress/` (keep `ddd-wordpress/self/index.php`, which packaging owns from the end of wave 2). `LegacyPathShimsTest` derives its shim list from the loader list, so it follows automatically.
- **packaging:** `phpstan.neon` and `phpstan-deadcode.neon` still list `ddd-src` and `ddd-wordpress` as paths; `phpstan-baseline.neon` paths point at the old locations.
- **packaging:** `ReleaseArtifactTest::shipped()` lists `ddd-src` (now absent; the check still passes because `git check-attr` does not need the path to exist).
- **conformance:** the file docblock of `packages/ddd-conformance/tests/bootstrap.php` still describes the wave-1 `ddd-src/` bridge; only the bridge line was mine to change.
