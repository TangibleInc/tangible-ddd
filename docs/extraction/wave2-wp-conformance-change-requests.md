# Wave 2 round 3: wp-conformance change requests

Author: wp-conformance (branch `wave2/wp-conformance`, based on `6258c0d`). Owned paths: `tests/Integration/Conformance/**`, `tests/Unit/Abi/**`, `packages/ddd-wp/tests/**`, the `conformance-wp` block of `tests/harness/run.sh`, and this file. No ratified interface, frozen FQCN or persisted name was changed. Everything this branch adds is test code; the requests below are for other owners.

## Status of the acceptance

- `tests/harness/run.sh conformance-wp` runs 32 tests (17 shared scenario methods on four wp classes, 3 wp audit cases, 2 catalogue checks, 2 fixture-parameter checks and 8 gate-rule checks; 2 wave-3 scenarios skipped, see "Scenarios not due on wp in wave 2"). **On this branch alone, 11 of the 12 wave-2 wp ids pass and the subcommand exits 1.** `relay.invalid-acceptance` fails on a defect in the transitional `WpdbOutboxStore` (WPC-5), which is outside this branch's owned paths. On a trial worktree of this branch's HEAD with only the WPC-5 and WPC-6 patches in the appendix applied, the run is 32 tests, 0 failures, 2 skipped, the gate prints `check-due: 12 of 12 scenario ids due on wp by wave 2 passed`, and the subcommand exits 0.
- `vendor/bin/phpunit` (root): 874 tests. On this branch alone, it is green except `tests/Unit/Loader/HarnessCliTest`'s data set `conformance-wp`, which pins the placeholder this task replaces (WPC-6). That data set also starts the real Docker harness from the unit suite. With WPC-6 applied in the trial worktree it is green (874 tests, 2.7 s) and runs no Docker.
- **Merge order (coordinator). This branch must not be merged alone.** Land the appendix patches WPC-5 (wp) and WPC-6 (packaging) before it or in the same merge. Merging this branch alone turns the integration branch's unit suite red, makes it start Docker, and leaves `conformance-wp` at 11 of 12. Both patches are outside this branch's owned paths, so they are not committed here. They are given verbatim (`git apply`-able against this branch's HEAD) in the appendix.
- **Gate (fix round 2).** `bin/check-due.php` now delegates to `Support/DueGate.php`, pinned by `WpDueGateConformance`. When an id is carried by more than one wp class, a skipped copy fails the id. The one tolerated skip is `audit.sink-fails`, and only while `TangibleDDD\Conformance\AuditSinkFaults` does not exist. Once `wave2/conformance-cleanup` lands, the shared `CommandScenarios::test_audit_sink_fails` must run on wp, so WPC-4 becomes mandatory and the wp-local copy can no longer mask it. `run.sh conformance-wp` now runs the gate even when phpunit is red, so the per-id verdicts always print.
- **Merge note: provisional ids on wp.** `relay.invalid-acceptance`, `relay.replay-keeps-identity`, `delivery.phase-order` and `delivery.delayed-once` are **provisional on wp in wave 2**. They are green on fixture stand-ins (WPC-1..WPC-3, below), not on the shipped wp relay path. When ddd-wp ships the Action Scheduler `ITransport`, the v8 ledger and IClock-aware adapters, wave 3 replaces the stand-ins and deletes `Support/clock-functions.php`.
- `tests/harness/run.sh wp-integration`: green, unchanged (27 tests). Files under `tests/Integration/Conformance/` end in `Conformance.php`, so `phpunit.integration.xml` never loads them.
- **What the green relay/delivery ids prove (WPC-1, WPC-2, WPC-3).** `relay.*` and `delivery.*` run the core port-form `OutboxProcessor` over a fixture `ITransport` (`Support/ActionSchedulerTransport.php`). Shipped wp still relays through `legacy_batch()` / `IOutboxPublisher`. `relay.replay-keeps-identity` and the delivery ids also go through a fixture ledger gate over `InMemoryDeliveryLedger` (`Support/LedgerGatedSubscriptions.php`). Scenario time reaches the 0.6 outbox code through namespaced `time()`/`gmdate()` shims (`Support/clock-functions.php`). On wp in wave 2, these ids pass on fixture stand-ins over the real wpdb store, the real Action Scheduler and real `add_action`/`do_action`. They do not prove the shipped wp relay path. Wave 3 replaces the stand-ins with ddd-wp code (WPC-1..WPC-3).
- **Fixture parameters (review minor).** `relayOnce($limit)` is now honoured: `RecordingOutboxStore::start($limit)` caps the relay step's claim, because the core step claims `OutboxConfig::$batch_size`. `deliverTransported($eventClass)` runs only the Action Scheduler actions whose hook is that class's `integration_action`. `WpFixtureParametersConformance` pins both. These checks are not catalogue ids, and `check-due` ignores them.

## WPC-5 (defect, blocks `relay.invalid-acceptance`): `WpdbOutboxStore::deadLetter()` does not count the final attempt

- **Owner.** wp (`packages/ddd-wp/wordpress/Adapter/WpdbOutboxStore.php`).
- **Finding.** The relay dead-letters on the attempt that reaches `max_attempts`. That attempt never goes through `mark_failed()`, and `OutboxRepository::move_to_dlq()` copies the row's `attempts` as it stands. After 5 failed submissions the DLQ row and the outbox row say `attempts = 4`. The port contract and the mem double count all 5 (`InMemoryOutboxStore::deadLetter()` increments). The shared scenario asserts `DeadLetter::$attempts === 5`, and the wp run fails with `Failed asserting that 4 is identical to 5`. 0.6 fixed the same off-by-one for `last_error` (the `$final_error` argument) but not for `attempts`.
- **Patch (verified).** Count the final attempt before moving the row. This runs on the same wpdb connection and needs no schema change:

  ```php
  public function deadLetter(Claim $c, string $error): bool {
    $db = $GLOBALS['wpdb'];
    $db->query($db->prepare(
      "UPDATE `{$this->config->table('integration_outbox')}` SET attempts = attempts + 1, last_error = %s WHERE event_id = %s",
      $error,
      $c->event_id
    ));
    $this->repository->move_to_dlq($c->event_id, $error);
    return true;
  }
  ```

  Applied in a scratch worktree of this branch, `run.sh conformance-wp` went green with 12/12 due ids passing. The 0.6 relay form (`OutboxProcessor::legacy_batch()`) keeps writing the 0.6 count. Only the port-form store changes.
- **Compatibility.** The DLQ `attempts` column now says how many relay attempts were made. The dashboard shows the number as is. No reader depends on the off-by-one.

## WPC-6 (packaging): `HarnessCliTest` still pins `conformance-wp` as "not yet implemented"

- **Owner.** packaging (`tests/Unit/Loader/HarnessCliTest.php`).
- **Finding.** `later_waves()` lists `conformance-wp`, and the test runs `bash tests/harness/run.sh conformance-wp` expecting exit 2. That subcommand is now implemented, so the data set fails. Worse, it runs the real Docker harness from inside the unit suite: it exports HEAD, starts a throw-away MySQL 8.0 container and runs the conformance suite.
- **Requested change.** Drop `'conformance-wp' => ['conformance-wp']` from `later_waves()` and pin the dispatch statically, the way the loader case does:

  ```php
  public function test_the_conformance_wp_subcommand_is_wired(): void {
      $source = (string) file_get_contents(self::script());
      $this->assertMatchesRegularExpression('/^\s*conformance-wp\) conformance_wp ;;$/m', $source);
      $this->assertStringContainsString('tests/Integration/Conformance/phpunit.xml', $source);
      $this->assertStringContainsString('tests/Integration/Conformance/bin/check-due.php', $source);
  }
  ```

## WPC-7 (packaging, acknowledge): `run.sh` lines outside the `conformance-wp` function block

- **What.** Besides the new `conformance_wp()` function, this branch changes two lines of `tests/harness/run.sh` that packaging owns:
  - the `usage()` line for `conformance-wp`. It said "(not yet implemented)" and now says "conformance scenarios on WordPress + MySQL 8.0, fresh database (wave-2 wp ids gated)".
  - the dispatch. `conformance-wp` moves out of the `not_yet` case arm into its own arm, `conformance-wp) conformance_wp ;;`.
- **Why.** Leaving either line as it was would have left the subcommand advertised as unimplemented, or still routed to `not_yet`. The function itself is the placeholder this task replaces.
- **Request.** Packaging acknowledges both lines. WPC-6's static test pins the dispatch arm.
- **Compatibility.** None. The harness CLI is internal.

## WPC-4 (coordinator, after merging `wave2/conformance-cleanup`): declare the CR-CC-1 seams on `WpHostFixture`

- **What.** `wave2/conformance-cleanup` adds the optional seams `TangibleDDD\Conformance\AuditSinkFaults` (`failNextAuditClose(string): void`) and `RecordsSignals` (`signals(): array`), and a shared `CommandScenarios::test_audit_sink_fails` that is skipped on hosts without them. `WpHostFixture` already has both methods with exactly those shapes, but cannot name the interfaces because they are not on this branch's base. After both merges, change the class line to

  ```php
  final class WpHostFixture implements HostFixture, AuditSinkFaults, RecordsSignals {
  ```

  On a trial merge of both branches with that one line, the shared `test_audit_sink_fails` passes on wp: 23 tests, with only the WPC-5 failure and the 2 wave-3 skips.
- **Until then.** `WpAuditConformance` carries `audit.sink-fails` on wp (`#[Group('audit.sink-fails')]`, `test_audit_sink_fails`), plus open-phase and error-path cases. `bin/check-due.php` (`Support/DueGate`) tolerates the shared copy being skipped on `WpCommandConformance` only while `TangibleDDD\Conformance\AuditSinkFaults` does not exist. Once that interface is on the branch, a skipped shared copy fails the gate until WPC-4 is applied, and both copies must pass.
- **Compatibility.** Additive.

## WPC-1 (wp, wave 3): ship an Action Scheduler `ITransport`

- **Finding.** ddd-wp has no `ITransport` in wave 2. Its relay is still the 0.6 `OutboxProcessor(config, IOutboxRepository, OutboxConfig, IOutboxPublisher)` form, and `IOutboxPublisher::publish()` returns no reference, so CONF-4 ("every transport must issue a reference") cannot hold on wp. The conformance host therefore relays with the core port-form `OutboxProcessor` over `tests/Integration/Conformance/Support/ActionSchedulerTransport.php`.
- **Request.** ddd-wp ships `TangibleDDD\WordPress\Adapter\ActionSchedulerTransport implements ITransport` with the fixture's semantics:
  - `submit()` calls `as_schedule_single_action($dueAt, $record->integration_action, [$wrapped], $group)`. The absolute due time is kept even when it is already past, because Action Scheduler runs past-due actions at once. That keeps `delivery.delayed-once`'s "the retry kept the absolute due time" true, which `as_enqueue_async_action` (stamped "now") would not.
  - The action id is the reference, and `0` becomes `'0'`, a rejection.
  - `sharesConnectionWith()` returns true for the wpdb outbox store.
  
  Then `HostDefaults` can wire the port-form relay on wp.
- **Compatibility.** New class. AS hook, args and group are the 0.6 ones, pinned by `GoldenDerivedNamesTest`.

## WPC-2 (wp, wave 3): per-subscriber ledger on wp delivery

- **Finding.** `WpHookSubscriptionRegistry` has no ledger before schema v8 (its docblock says so), yet `relay.replay-keeps-identity` is due on wp in wave 2, and it asserts that subscribers already in the ledger are skipped. The conformance host wraps each subscriber's handle in a ledger gate (`Support/LedgerGatedSubscriptions.php`) over `Testing\InMemoryDeliveryLedger`, inside the real `add_action` callback. Delivery is the real Action Scheduler action (`ActionScheduler::runner()->process_action()`), or `do_action` on the fact's hook. The gate behaves like `IntegrationDelivery`: skip when delivered, `markDelivered` on success, `markFailed` with the next attempt on a throw, and do_action continues.
- **Request.** The wave-3 "per-callback invoker wrapping of DDD-registered callbacks" over `{prefix}_ddd_delivery_ledger` replaces the gate. The fixture then drops it and returns the wp ledger from `ledger()`.

## WPC-3 (wp, wave 3): the transitional outbox adapters read the wall clock

- **Finding.** `OutboxRepository` (under `WpdbOutboxStore`) and `WpdbOutboxAdministration` call `time()` / `gmdate()`. `WpdbOutboxStore::claim()` ignores both `$now` and `$leaseSeconds` (fixed 300 s). The `HostFixture` contract says every port reads the host `IClock`, and the relay and delivery scenarios advance it by up to 5 × 3601 s.
- **Fixture bridge.** `tests/Integration/Conformance/Support/clock-functions.php` defines `time()` and `gmdate()` in `TangibleDDD\Infra\Persistence` and `TangibleDDD\WordPress\Adapter`, routed to the scenario clock (`ScenarioTime`, real time when no scenario is running). This is Symfony ClockMock's technique. The conformance bootstrap loads it before Composer's autoloader. The wp-integration suite never loads it.
- **Request.** The wave-3 wp adapters take an `IClock` (constructor, else `HostDefaults::get(IClock::class)`) and honour `claim($now, $leaseSeconds)`. The shims can then go.

## Scenarios not due on wp in wave 2

| Id (wp wave) | Status on this branch |
|---|---|
| `relay.lease-fencing` (3) | skipped: no `claim_token` before v8; fixed 300 s lease; unfenced writes. `WpRelayConformance::whileLeased()` already asserts that a 0.6 `fetch_pending()` skips a leased row, for wave 3. |
| `relay.pause-holders` (3) | skipped: no `IRelayPauseStore` on wp before v8 pause rows. The 0.6 option matches exact event types, not selector globs. `relayPauses()` throws `LogicException`. |
| `relay.crash-after-submit` (3) | **passes** (shared `$wpdb` connection: submit and accept in one transaction) |
| `delivery.double-delivery`, `delivery.subscriber-isolation` (3) | **pass** with the WPC-2 gate |
| `worker.no-leak` (3) | **passes** (`ReentrantProcessLock(GetLockProcessLock)` guarded by `RuntimeReset`; `runnerTransients()` is null until a ProcessRunner is wired on the conformance host) |

## ABI freeze tests (register section 8, wave 2 wp bullet), for reviewers

All are under `tests/Unit/Abi` and run in the root suite.

- **B14** `ProceduralSignatureSnapshotTest`. Static snapshots (nikic/php-parser) of every function and namespace constant under `ddd-wordpress/` at v0.6.0 and v0.6.2..v0.6.6. Each is compared with N's `packages/ddd-wp/wordpress/` under the B14 rule: same FQN; parameter names, types, by-ref and defaults kept; only optional parameters appended. N is also frozen in `fixtures/procedural/current.json`. `php tests/Unit/Abi/bin/generate-fixtures.php [--current]` regenerates the snapshots. It relies on `nikic/php-parser`, which comes in transitively through phpunit/phpstan in require-dev. **WPC-8 (packaging, review minor):** add `"nikic/php-parser": "^5"` to root `require-dev`, so an upstream bump cannot silently drop it.
- **B9** `HistoricalScaffoldCompileTest`. The `wp ddd init` output of each tag (v0.6.3..v0.6.6 equal v0.6.2, see the manifest) compiles following that tag's `di/index.php`, and every public service resolves. A transactional command with an announcing event then runs through the scaffolded bus and returns its value.
- **B15** `GoldenDerivedNamesTest`. Literal 0.6.6 names, observed on N's code paths. Not covered: `tangible_ddd_dashboard_consumer_accent` (dashboard-only filter).
- **D F4** `ConsumerConfigShapeTest`. Verbatim, sha256-pinned copies of the cred, lms, quiz, certificates (`TangibleInc/tangible-certificates@1ca7ff5`) and datastream (`@04418d5`) `IDDDConfig` implementations load unchanged in separate processes and are accepted by `ConsumerRegistry`, the 0.6 constructors and `HostDefaults::for()`. `IDDDConfig` keeps exactly its eight methods.

## Appendix: patches for other owners (apply with or before this branch)

Both were applied to a detached trial worktree at this branch's HEAD and verified there: `run.sh conformance-wp` exit 0 with 12 of 12, root `vendor/bin/phpunit` 874 green with no Docker. Apply with `git apply` from the repo root.

### WPC-5 (wp)

```diff
diff --git a/packages/ddd-wp/wordpress/Adapter/WpdbOutboxStore.php b/packages/ddd-wp/wordpress/Adapter/WpdbOutboxStore.php
index 8141596..e13d30e 100644
--- a/packages/ddd-wp/wordpress/Adapter/WpdbOutboxStore.php
+++ b/packages/ddd-wp/wordpress/Adapter/WpdbOutboxStore.php
@@ -121,6 +121,14 @@ final class WpdbOutboxStore implements IOutboxStore {
   }
 
   public function deadLetter(Claim $c, string $error): bool {
+    // The dead-lettering attempt never went through markFailed(); count it
+    // before move_to_dlq() copies the row (port contract: attempts made).
+    $db = $GLOBALS['wpdb'];
+    $db->query($db->prepare(
+      "UPDATE `{$this->config->table('integration_outbox')}` SET attempts = attempts + 1, last_error = %s WHERE event_id = %s",
+      $error,
+      $c->event_id
+    ));
     $this->repository->move_to_dlq($c->event_id, $error);
     return true;
   }
```

### WPC-6 (packaging)

```diff
diff --git a/tests/Unit/Loader/HarnessCliTest.php b/tests/Unit/Loader/HarnessCliTest.php
index be64901..99b51a5 100644
--- a/tests/Unit/Loader/HarnessCliTest.php
+++ b/tests/Unit/Loader/HarnessCliTest.php
@@ -44,10 +44,19 @@ class HarnessCliTest extends TestCase
         return [
             'core-pdo' => ['core-pdo'],
             'compat' => ['compat'],
-            'conformance-wp' => ['conformance-wp'],
         ];
     }
 
+    public function test_the_conformance_wp_subcommand_is_wired(): void
+    {
+        // Running it needs Docker and MySQL 8.0 (CI and by hand); here only
+        // the dispatch and the suite + gate it runs.
+        $source = (string) file_get_contents(self::script());
+        $this->assertMatchesRegularExpression('/^\s*conformance-wp\) conformance_wp ;;$/m', $source);
+        $this->assertStringContainsString('tests/Integration/Conformance/phpunit.xml', $source);
+        $this->assertStringContainsString('tests/Integration/Conformance/bin/check-due.php', $source);
+    }
+
     #[DataProvider('later_waves')]
     public function test_subcommands_of_later_waves_exit_2_not_yet_implemented(string $sub): void
     {
```
