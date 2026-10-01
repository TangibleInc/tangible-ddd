<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Defaults\Pdo\Internal\Glob;
use TangibleDDD\Defaults\Pdo\Internal\Utc;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\PrefixedTableNames;
use TangibleDDD\Runtime\SystemClock;

/**
 * IRelayPauseStore on `{prefix}ddd_relay_pauses` (register 3.4, C25): one row
 * per (holder, selector), fnmatch selectors, expiry honoured (`held_until`
 * <= now is released). hold() is an upsert.
 *
 * patterns() exposes the live selectors as anchored regexes so
 * PdoOutboxStore can exclude paused rows inside its claim statement instead
 * of claiming and handing them back.
 *
 * Errors: storage failures throw the driver exception.
 */
final class PdoPauseStore implements IRelayPauseStore {

  private readonly string $table;
  private readonly IClock $clock;

  public function __construct(private readonly IHostConnection $db, string $tablePrefix = '', ?IClock $clock = null) {
    $this->table = (new PrefixedTableNames($tablePrefix))->table('ddd_relay_pauses');
    $this->clock = $clock ?? new SystemClock();
  }

  public function connection(): IHostConnection {
    return $this->db;
  }

  public function hold(string $holder, string $selector, ?\DateTimeImmutable $until): void {
    $untilDb = $until === null ? null : Utc::to_db($until);
    $this->db->execute(
      "INSERT INTO `{$this->table}` (holder, selector, held_until, created_at) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE held_until = ?",
      [$holder, $selector, $untilDb, Utc::to_db($this->clock->now()), $untilDb]
    );
  }

  public function release(string $holder, ?string $selector = null): void {
    if ($selector === null) {
      $this->db->execute("DELETE FROM `{$this->table}` WHERE holder = ?", [$holder]);
      return;
    }
    $this->db->execute("DELETE FROM `{$this->table}` WHERE holder = ? AND selector = ?", [$holder, $selector]);
  }

  public function is_paused(string $eventType, \DateTimeImmutable $now): bool {
    foreach ($this->selectors($now) as $selector) {
      if (Glob::matches($selector, $eventType)) {
        return true;
      }
    }
    return false;
  }

  /** @return list<string> anchored regexes (MySQL REGEXP_LIKE / PCRE) of the live selectors */
  public function patterns(\DateTimeImmutable $now): array {
    return array_values(array_unique(array_map(Glob::to_regex(...), $this->selectors($now))));
  }

  /** @return list<string> */
  private function selectors(\DateTimeImmutable $now): array {
    $rows = $this->db->fetch_all(
      "SELECT DISTINCT selector FROM `{$this->table}` WHERE held_until IS NULL OR held_until > ? ORDER BY selector",
      [Utc::to_db($now)]
    );
    return array_map(static fn (array $r) => (string) $r['selector'], $rows);
  }
}
