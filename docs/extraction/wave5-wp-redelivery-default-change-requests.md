# Wave 5 change requests: wp-redelivery-default

Branch `wave5/wp-redelivery-default`. Two parts: the coordinator decision on the default redelivery of WordPress listeners, and the cleanup of the LMS fix for a loader that defers to a `plugins_loaded` that never fires. Every change below is additive. No ratified interface changes.

## Behaviour (coordinator decision, operator approved)

A DDD-registered listener of a WordPress consumer gets **one attempt** by default, as in 0.6. "Listener" means every DDD subscriber that is not part of the process kernel: `integration_action()`, `integration_listener()` / `IntegrationListener`, a `SubscriptionRegistrar` listener (`listener:<class>`), and any custom `Subscriber`.

- On its first throw the pair is written to `{prefix}_ddd_delivery_ledger` as `failed` (attempts 1, `last_error`) and then marked `exhausted`. Its on-exhausted compensation runs once; for a D1 listener that is `failure_command()`. No `{prefix}_ddd_redeliver` is scheduled.
- `wp ddd ops` (`WpOperatorView`) lists the pair. The `budget` column now shows that subscriber's real budget instead of a constant 5.
- Process ignition, process resume and workflow ignition subscribers (`[{prefix}/](ignition|resume|workflow-ignition):…`) keep the core budget `IntegrationDelivery::DEFAULT_BUDGET` (5). The option, the attribute and the filter do not apply to them.

Opt-in, independent of `IDDDConfig`, resolved per delivery by `WpLedgeredDelivery::budget($prefix, $subscriber_id)`:

1. `#[TangibleDDD\WordPress\Retries(n)]` declared by the listener: n retries, so n + 1 attempts;
2. otherwise the option `{prefix}_ddd_delivery_attempts`, when it holds a positive int;
3. otherwise `LISTENER_ATTEMPTS` (1);
4. then the filter `tangible_ddd_delivery_attempts` (`$attempts, $subscriber_id, $prefix`) has the last word. The result is never below 1.

Tests: `tests/Unit/WordPress/Adapter/WpDeliveryBudgetTest.php` (resolution order, kernel ids, filter, attribute targets) and `tests/Integration/V8/WpDeliveryBudgetV8Test.php`. The integration test covers:

- the default is one attempt, the ledger shows it exhausted with `last_error`, and nothing is scheduled or restored;
- the operator view row;
- an opted-in consumer budget, with a listener that recovers inside it;
- `#[Retries]` on a closure and on a registrar class;
- the filter;
- a D1 listener's `failure_command` fires exactly once on the single attempt;
- ignition and resume keep 5.

## CR-RD-1: WpLedgeredDelivery budget surface (ddd-wp, additive)

- `bind(..., ?\Closure $onExhausted = null, ?int $attempts = null)`: a new optional trailing parameter carrying the attempts a listener declares. Existing calls are unchanged.
- `public static function budget(string $prefix, string $subscriberId): int`.
- Constants: `LISTENER_ATTEMPTS = 1`, `ATTEMPTS_OPTION = 'ddd_delivery_attempts'` (used through `IDDDConfig::option()`), `ATTEMPTS_FILTER = 'tangible_ddd_delivery_attempts'`. `BUDGET` (5) stays and now means the kernel budget.
- `gate()`, `exhaust()` and `spendUnbound()` use the per-subscriber budget. An unbound pair uses its consumer's budget, or the declared one if it was bound earlier in the request.

## CR-RD-2: `TangibleDDD\WordPress\Retries` attribute (ddd-wp, new class)

`#[Retries(int $count)]` targets a class, a method or a function (closures included). It exposes `attempts(): int` (`count + 1`) and `static of(mixed $listener): ?self`. `of()` reads, in order:

- a class name or object: the class;
- `[object|class, method]` and `'Class::method'`: the method first, then the class;
- a closure;
- a function name.

PHP attributes are not inherited, so a subclass declares its own. A negative count throws `\InvalidArgumentException`.

Who reads it:

- `integration_action()` reads the callback;
- `integration_listener()` reads the listener object bound to the translate closure, or the callable itself;
- `WpHookSubscriptionRegistry::add()` reads the class named in a `listener:<class>` subscriber id. That is the `SubscriptionRegistrar` id format of register 3.5.

Note for core, not a request this round: if the other hosts want a per-listener budget, the cleaner long-term shape is an optional `?int $attempts` on `Subscriber`, set by `SubscriptionRegistrar` from a core `Runtime\Delivery\Retries`. wp would then stop parsing ids. Until then, the wp kernel classification relies on the stable id formats of `SubscriptionRegistrar` (`ignition:`, `resume:`), `ProcessRunner` (`{prefix}/ignition:`, `{prefix}/resume:`) and `WorkflowIgniter` (`{prefix}/workflow-ignition:`). Those formats must stay stable. They already key the ledger.

## CR-RD-3: wiring when the loader defers to a `plugins_loaded` that never fires (ddd-wp, additive)

- New procedural file `packages/ddd-wp/wordpress/unbooted.php` with `TangibleDDD\WordPress\wire_unbooted(string $root): void`, guarded by `function_exists`. It does nothing when WPINC is defined (real WordPress always fires `plugins_loaded`), when `add_action` is missing, or when `plugins_loaded` already fired. In all three cases it loads no class.
- `HostDefaultsWiring::register_unbooted(string $root)`: a one-shot HostDefaults miss resolver, guarded to the winner's classes:
  - once `Tangible_DDD_Versions` has initialized a winner, the resolver steps aside and removes itself;
  - otherwise it fills only when the loaded `HostDefaults` comes from `$root` and, when copies have registered, `$root` is the latest copy.
- The procedural snapshot (`tests/Unit/Abi/fixtures/procedural/current.json`) is re-frozen with the one added function.
- Tests: `tests/Unit/WordPress/LateWordPressBootTest.php`, run through `fixtures/late-wordpress-boot.php`. The LMS stubs moved to `fixtures/lms-wordpress-stubs.php`. Two modes:
  - `stubs-first`: the 0.6 scaffold resolves with every WordPress port;
  - `stubs-first-foreign`: another root wires nothing.

  There is also an in-process step-aside test. Until packaging lands the loader line below, the fixture calls `wire_unbooted()` itself and reports `loader_wires`.

## Requests to other owners

1. **Packaging (root `tangible-ddd.php`).** At the end of the file, after the late-load block, add:

   ```php
   // ─── Stubbed WordPress that never fires plugins_loaded ─────────────────────
   // A test bootstrap that defines add_action before vendor/autoload.php makes
   // this copy wait for plugins_loaded:0/1. If that never fires, hooks.php
   // never wires HostDefaults; ddd-wp's unbooted wiring does it on the first
   // miss (a no-op under real WordPress, guarded to the winner's classes).
   if (function_exists('add_action') && !Tangible_DDD_Versions::instance()->is_initialized()
       && is_file(__DIR__ . '/packages/ddd-wp/wordpress/unbooted.php')) {
       require_once __DIR__ . '/packages/ddd-wp/wordpress/unbooted.php';
       \TangibleDDD\WordPress\wire_unbooted(__DIR__);
   }
   ```

   After that, `LateWordPressBootTest` stubs-first reports `loader_wires: true` and runs end to end through the real loader. The fixture's fallback call can stay; it is skipped once the loader wires.
2. **Packaging (`CHANGELOG.md`, 0.7.0).** Text in the return payload (`api_change_requests`): one `### Changed` bullet and one migration step.
3. **Conformance (optional).** The shared scenarios assert the core budget, so `tests/Integration/bootstrap.php` (owned here) opts the `ddd_conformance` consumer in through the filter. If the conformance owner prefers, move that opt-in into `WpConformanceRuntime` (for example as a filter registered in its boot) and drop it from the bootstrap.
4. **Compat rollback fixtures (`tests/Compat/rollback/**`).** This one is needed for the `compat` gate (7.3). `NRowsRolledBackRollback` relies on the RbNote listener leaving a pending `{prefix}_ddd_redeliver` after one failure. Under the new default the listener is exhausted instead. Verified: `tests/harness/run.sh compat` on this branch gives cs, allowances, artifact and 7.2 ok, and 7.3 FAILED with 6 failures, all in `failOnce()` (line 190, `assertCount(1, pending('ddd_redeliver'))`), across the `test_a_pending_redelivery_is_lost_on_rollback_without_the_drain` and `test_drain_before_rollback_empties_the_pending_redeliveries_first` cases × 0.6.2/0.6.5/0.6.6. The fix is one line at the top of `failOnce()`:

   ```php
   update_option($this->config->option(WpLedgeredDelivery::ATTEMPTS_OPTION), WpLedgeredDelivery::BUDGET, false);
   ```

   A `#[Retries(4)]` on the RbNote callback works too.
