<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use Doctrine\DBAL\Connection;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\PrefixedTableNames;

/**
 * IRelayPauseStore on `{prefix}ddd_relay_pauses` (register 3.4, C25): one
 * row per (holder, selector), fnmatch selectors, expiry honoured (`until` <=
 * now is released). hold() is an upsert.
 *
 * activePatterns() exposes the live selectors as anchored regexes so
 * DbalPostgresOutboxStore can exclude paused rows inside its single claim
 * statement instead of claiming and releasing them.
 *
 * Errors: storage failures throw the DBAL exception.
 */
final class DbalRelayPauseStore implements IRelayPauseStore {

  private readonly string $table;

  public function __construct(private readonly Connection $connection, string $tablePrefix = '') {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_relay_pauses');
  }

  public function connection(): Connection {
    return $this->connection;
  }

  public function hold(string $holder, string $selector, ?\DateTimeImmutable $until): void {
    $this->connection->executeStatement(
      "INSERT INTO {$this->table} (holder, selector, until) VALUES (?, ?, ?)
       ON CONFLICT (holder, selector) DO UPDATE SET until = EXCLUDED.until",
      [$holder, $selector, $until === null ? null : Time::toDb($until)]
    );
  }

  public function release(string $holder, ?string $selector = null): void {
    if ($selector === null) {
      $this->connection->executeStatement("DELETE FROM {$this->table} WHERE holder = ?", [$holder]);
      return;
    }
    $this->connection->executeStatement("DELETE FROM {$this->table} WHERE holder = ? AND selector = ?", [$holder, $selector]);
  }

  public function isPaused(string $eventType, \DateTimeImmutable $now): bool {
    foreach ($this->activeSelectors($now) as $selector) {
      if ($selector === $eventType || fnmatch($selector, $eventType)) {
        return true;
      }
    }
    return false;
  }

  /** @return list<string> anchored regexes (Postgres `~` / PCRE) of the live selectors */
  public function activePatterns(\DateTimeImmutable $now): array {
    return array_values(array_unique(array_map(GlobPattern::toRegex(...), $this->activeSelectors($now))));
  }

  /** @return list<string> */
  private function activeSelectors(\DateTimeImmutable $now): array {
    return array_map('strval', $this->connection->fetchFirstColumn(
      "SELECT DISTINCT selector FROM {$this->table} WHERE until IS NULL OR until > ?",
      [Time::toDb($now)]
    ));
  }
}
