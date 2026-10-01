<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Support\ConsumerTables;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\SystemClock;

/**
 * The wp IDeliveryLedger (register 3.5, 5.1; CR-1; WPC-2) over
 * `{prefix}_ddd_delivery_ledger` of the FACT's consumer (the prefix that
 * owns the integration hook), one row per (subscriber, event_id), keyed by
 * sha1(subscriber_id) so long ids index uniquely.
 *
 * status: `failed` (attempts counted, last_error kept) → `delivered`, or
 * `exhausted` (exhausted_at set, the terminal marker of CR-1). A delivered
 * pair is never downgraded by a late markFailed().
 *
 * Every write is a single upsert on the WordPress connection, outside any
 * transaction of its own (subscribers commit their commands themselves).
 * Storage failures throw \RuntimeException so the fact is retried whole.
 */
final class WpDeliveryLedger implements IDeliveryLedger {

  public function __construct(
    private readonly string $prefix,
    private readonly ?IClock $clock = null,
  ) {}

  public function delivered(string $subscriberId, string $eventId): bool {
    return $this->status($subscriberId, $eventId) === 'delivered';
  }

  public function markDelivered(string $subscriberId, string $eventId): void {
    $now = $this->stamp();
    $this->write(
      "INSERT INTO `{$this->table()}` (subscriber_key, subscriber_id, event_id, status, attempts, delivered_at, created_at, updated_at)
       VALUES (%s, %s, %s, 'delivered', 0, %s, %s, %s)
       ON DUPLICATE KEY UPDATE status = 'delivered', delivered_at = VALUES(delivered_at), updated_at = VALUES(updated_at)",
      [sha1($subscriberId), $subscriberId, $eventId, $now, $now, $now],
      'markDelivered'
    );
  }

  public function markFailed(string $subscriberId, string $eventId, string $error, int $attempt): void {
    $now = $this->stamp();
    $this->write(
      "INSERT INTO `{$this->table()}` (subscriber_key, subscriber_id, event_id, status, attempts, last_error, created_at, updated_at)
       VALUES (%s, %s, %s, 'failed', %d, %s, %s, %s)
       ON DUPLICATE KEY UPDATE
         attempts = IF(status = 'delivered', attempts, VALUES(attempts)),
         last_error = IF(status = 'delivered', last_error, VALUES(last_error)),
         updated_at = VALUES(updated_at)",
      [sha1($subscriberId), $subscriberId, $eventId, $attempt, $error, $now, $now],
      'markFailed'
    );
  }

  public function attempts(string $subscriberId, string $eventId): int {
    return (int) $this->column('attempts', $subscriberId, $eventId);
  }

  public function lastError(string $subscriberId, string $eventId): ?string {
    $v = $this->column('last_error', $subscriberId, $eventId);
    return $v === null ? null : (string) $v;
  }

  public function markExhausted(string $subscriberId, string $eventId): void {
    $now = $this->stamp();
    $this->write(
      "INSERT INTO `{$this->table()}` (subscriber_key, subscriber_id, event_id, status, attempts, exhausted_at, created_at, updated_at)
       VALUES (%s, %s, %s, 'exhausted', 0, %s, %s, %s)
       ON DUPLICATE KEY UPDATE status = IF(status = 'delivered', status, 'exhausted'),
         exhausted_at = COALESCE(exhausted_at, VALUES(exhausted_at)), updated_at = VALUES(updated_at)",
      [sha1($subscriberId), $subscriberId, $eventId, $now, $now, $now],
      'markExhausted'
    );
  }

  public function exhausted(string $subscriberId, string $eventId): bool {
    return $this->status($subscriberId, $eventId) === 'exhausted';
  }

  /**
   * Operator view rows: failed (still under budget or compensation pending)
   * and exhausted pairs, newest first.
   *
   * @return list<array{subscriber_id: string, event_id: string, status: string, attempts: int, last_error: ?string, updated_at: string}>
   */
  public function problems(int $limit = 100): array {
    $db = self::db();
    $rows = $db->get_results($db->prepare(
      "SELECT subscriber_id, event_id, status, attempts, last_error, updated_at FROM `{$this->table()}`
       WHERE status IN ('failed', 'exhausted') ORDER BY updated_at DESC, id DESC LIMIT %d",
      max(0, $limit)
    ), ARRAY_A);
    return array_map(static fn (array $r) => [
      'subscriber_id' => (string) $r['subscriber_id'],
      'event_id' => (string) $r['event_id'],
      'status' => (string) $r['status'],
      'attempts' => (int) $r['attempts'],
      'last_error' => $r['last_error'] === null ? null : (string) $r['last_error'],
      'updated_at' => (string) $r['updated_at'],
    ], is_array($rows) ? $rows : []);
  }

  private function status(string $subscriberId, string $eventId): ?string {
    $v = $this->column('status', $subscriberId, $eventId);
    return $v === null ? null : (string) $v;
  }

  private function column(string $column, string $subscriberId, string $eventId): mixed {
    $db = self::db();
    $v = $db->get_var($db->prepare(
      "SELECT `$column` FROM `{$this->table()}` WHERE subscriber_key = %s AND event_id = %s",
      sha1($subscriberId),
      $eventId
    ));
    if ($v === null && $db->last_error !== '') {
      throw new \RuntimeException("Delivery ledger read failed: {$db->last_error}");
    }
    return $v;
  }

  /** @param list<int|string> $args */
  private function write(string $sql, array $args, string $what): void {
    $db = self::db();
    if ($db->query($db->prepare($sql, ...$args)) === false) {
      throw new \RuntimeException("Delivery ledger $what failed: " . (string) $db->last_error);
    }
  }

  private function stamp(): string {
    return ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now()
      ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  }

  private function table(): string {
    return ConsumerTables::name($this->prefix, 'ddd_delivery_ledger');
  }

  private static function db(): \wpdb {
    return $GLOBALS['wpdb'];
  }
}
