<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

/**
 * Driver-level fault injection on the WordPress connection, through
 * wpdb's own `query` filter (applied to every statement wpdb::query()
 * sends). Each fault fires once.
 *
 * - failNextCommit(): the next `COMMIT` is replaced by a SIGNAL, so MySQL
 *   answers it with error 1644 and the transaction stays open; the checked
 *   boundary sees wpdb::query() === false, exactly as for a real COMMIT
 *   failure, and must roll back.
 * - throwOnNext($verb, $table): the next `INSERT INTO` / `UPDATE` of
 *   $table throws from inside wpdb (the audit sink failing on open, or
 *   after the domain commit on close).
 */
final class WpdbFaults {

  /** @var list<\Closure(string): string> */
  private array $armed = [];

  private ?\Closure $filter = null;

  public function install(): void {
    $this->filter = function (string $sql): string {
      foreach ($this->armed as $i => $fault) {
        try {
          $rewritten = $fault($sql);
        } catch (\Throwable $e) {
          $this->disarm($i);
          throw $e;
        }
        if ($rewritten !== $sql) {
          $this->disarm($i);
          return $rewritten;
        }
      }
      return $sql;
    };
    add_filter('query', $this->filter, PHP_INT_MAX, 1);
  }

  public function uninstall(): void {
    if ($this->filter !== null) {
      remove_filter('query', $this->filter, PHP_INT_MAX);
      $this->filter = null;
    }
    $this->armed = [];
  }

  private function disarm(int $i): void {
    unset($this->armed[$i]);
    $this->armed = array_values($this->armed);
  }

  public function failNextCommit(string $reason): void {
    $message = substr(str_replace("'", '', $reason), 0, 120);
    $this->armed[] = static fn (string $sql): string => strtoupper(trim($sql)) === 'COMMIT'
      ? "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'"
      : $sql;
  }

  /** @param 'INSERT INTO'|'UPDATE' $verb */
  public function throwOnNext(string $verb, string $table, \Throwable $e): void {
    $pattern = '/^\s*' . str_replace(' ', '\s+', preg_quote($verb, '/')) . '\s+`?' . preg_quote($table, '/') . '`?\s/i';
    $this->armed[] = static function (string $sql) use ($pattern, $e): string {
      if (preg_match($pattern, $sql) === 1) {
        throw $e;
      }
      return $sql;
    };
  }
}
