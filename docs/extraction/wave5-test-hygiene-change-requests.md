# Wave 5 test-hygiene: change requests

No contract (interface) changes. One request for a file outside this
author's owned paths, needed for the random-order root suite to be green on
every seed.

## CR-W5TH-1: SelfConsumerRegistrationTest resets ConsumerRegistry in setUp

- **File:** `tests/Unit/WordPress/SelfConsumerRegistrationTest.php` (not owned here).
- **Defect:** `test_a_missing_self_container_registers_nothing` asserts
  `ConsumerRegistry::all() === []`, but the class only resets the registry in
  `tearDown()`. Any earlier test that leaves a consumer registered breaks it.
  Observed leftovers: `dump_catalog`
  (`tests/Unit/DependencyInjection/DumpedLongProcessCatalogTest`, which never
  resets), `test` (`FakeDDDConfig`) and `acme` (`Fakes\Acme\Infra\Config`)
  from tests that reset only in `setUp()`. Resetting in the victim's `setUp()`
  covers all of them; chasing every polluter does not scale.
- **Seen on:** `vendor/bin/phpunit --do-not-cache-result --order-by=random
  --random-order-seed=N` for N = 27, 41, 49, 50, 53, 58, 62 (scan of seeds
  1..80 on top of the ProcessRunnerTest and LoadDiagnosticsTest fixes on this
  branch; about 9% of seeds). The failing seeds shift whenever a test is
  added or removed, so the earlier scan (10, 19, 23, 40 of 1..70) no longer
  reproduces. Seeds 1..10 are currently green.
- **Fix (verified locally, then reverted because the path is not owned):**

  ```php
  protected function setUp(): void {
    ConsumerRegistry::reset();
  }
  ```

  With it, all seeds 1..80 pass.
- **Follow-up once applied:** switch `phpunit.xml` to
  `executionOrder="depends,random"` (`git revert c1b3f3a` on this branch).
  It is held back so the default run stays deterministic and green; with
  random order and no CR-W5TH-1, roughly one default run in eleven would fail.
- **Optional, same root cause:** `DumpedLongProcessCatalogTest` should also
  reset `ConsumerRegistry` (and restore global `$wpdb`; its stub answers a
  table name to every `get_var()`, GET_LOCK included) in `tearDown()`.
