# Wave 5 test-hygiene: change requests

No contract (interface) changes. One request for a file outside this
author's owned paths, needed for the random-order root suite to be green on
every seed.

## CR-W5TH-1: SelfConsumerRegistrationTest resets ConsumerRegistry in setUp

- **File:** `tests/Unit/WordPress/SelfConsumerRegistrationTest.php` (not owned here).
- **Defect:** `test_a_missing_self_container_registers_nothing` asserts
  `ConsumerRegistry::all() === []`, but the class only resets the registry in
  `tearDown()`. `tests/Unit/DependencyInjection/DumpedLongProcessCatalogTest`
  registers the `dump_catalog` consumer and never resets it, so whenever
  that test runs earlier the assertion fails.
- **Seen on:** `vendor/bin/phpunit --order-by=random --random-order-seed=N`
  for N = 10, 19, 23, 40 (scan of seeds 1..70, after the ProcessRunnerTest
  and LoadDiagnosticsTest fixes on this branch).
- **Fix (verified locally, then reverted because the path is not owned):**

  ```php
  protected function setUp(): void {
    ConsumerRegistry::reset();
  }
  ```

  With it, seeds 1..10, 19, 23 and 40 all pass.
- **Optional, same root cause:** `DumpedLongProcessCatalogTest` should also
  reset `ConsumerRegistry` (and restore global `$wpdb`; its stub answers a
  table name to every `get_var()`, GET_LOCK included) in `tearDown()`.
