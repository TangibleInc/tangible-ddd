<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

/**
 * One-shot statement faults shared by every connection of an sf fixture
 * (the ScenarioSchemaMiddleware of each consults the same instance).
 *
 * failNextAdvisoryLock(): the NEXT `pg_try_advisory_lock(?)` statement, on
 * whichever connection runs it, is replaced by one that the server rejects
 * with an error carrying $reason, and takes no lock. That is the sf form of
 * ProcessHost::fail_next_lock() (register 3.7: "the lock
 * backend returns NULL / false / an error"): PostgresAdvisoryProcessLock
 * sees a real query error.
 */
final class StatementFaults {

  /** @var list<string> */
  private array $advisoryLock = [];

  public function failNextAdvisoryLock(string $reason): void {
    $this->advisoryLock[] = $reason;
  }

  /** The SQL to prepare instead of $sql (the same placeholders), or $sql itself. */
  public function rewrite(string $sql): string {
    if ($this->advisoryLock === [] || !str_contains($sql, 'pg_try_advisory_lock(')) {
      return $sql;
    }
    $reason = str_replace("'", "''", array_shift($this->advisoryLock));
    // Casting the reason to an integer fails at the server with the reason in
    // the message (22P02); the lock function is never called.
    return "SELECT CAST(? AS BIGINT) + CAST('injected lock error: $reason' AS INTEGER)";
  }
}
