<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** Which test a fixture is set up for; SQL hosts derive their per-test schema name from it. */
final class ScenarioContext {

  private readonly string $unique;

  public function __construct(
    public readonly string $testClass,
    public readonly string $testMethod,
    public readonly ?string $scenarioId,
  ) {
    $this->unique = substr(sha1($testClass . '::' . $testMethod . '|' . getmypid() . '|' . hrtime(true)), 0, 12);
  }

  /**
   * A name unique to this test run, safe as a MySQL database / Postgres
   * schema name: lowercase [a-z0-9_], at most 63 characters, e.g.
   * `ddd_conf_mem_3f9a0c1b2d4e`.
   */
  public function uniqueName(string $host, string $prefix = 'ddd_conf'): string {
    return substr(strtolower(preg_replace('/[^a-z0-9_]/i', '_', "{$prefix}_{$host}_{$this->unique}")), 0, 63);
  }
}
