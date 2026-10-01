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
 * pair is never downgraded by a late mark_failed().
 *
 * Every write is a single upsert on the WordPress connection, outside any
 * transaction of its own (subscribers commit their commands themselves).
 * Storage failures throw \RuntimeException. WpLedgeredDelivery contains
 * them per subscriber: it logs the failure and schedules a whole-fact
 * `{prefix}_ddd_redeliver`, so the subscribers after it still run; only
 * when that redelivery cannot be scheduled either does the throw reach
 * do_action and fail the fact's Action Scheduler action (0.6-equivalent:
 * an operator retries the action).
 */
final class WpDeliveryLedger implements IDeliveryLedger {

  public function __construct(
    private readonly string $prefix,
    private readonly ?IClock $clock = null,
  ) {}

  public function delivered(string $subscriberId, string $eventId): bool {
    return $this->status($subscriberId, $eventId) === 'delivered';
  }

  public function mark_delivered(string $subscriberId, string $eventId): void {
    $now = $this->stamp();
    $this->write(
      "INSERT INTO `{$this->table()}` (subscriber_key, subscriber_id, event_id, status, attempts, delivered_at, created_at, updated_at)
       VALUES (%s, %s, %s, 'delivered', 0, %s, %s, %s)
       ON DUPLICATE KEY UPDATE status = 'delivered', delivered_at = VALUES(delivered_at), updated_at = VALUES(updated_at)",
      [sha1($subscriberId), $subscriberId, $eventId, $now, $now, $now],
      'mark_delivered'
    );
  }

  public function mark_failed(string $subscriberId, string $eventId, string $error, int $attempt): void {
    $this->mark_failed_with($subscriberId, $eventId, $error, $attempt, null);
  }

  /**
   * mark_failed() that also keeps the `{prefix}_ddd_redeliver` args of the
   * fact (['hook', 'event_class', 'payload']), so a redelivery Action
   * Scheduler lost can be scheduled again (restorable()).
   *
   * @param array<string, mixed>|null $redelivery
   */
  public function mark_failed_with(string $subscriberId, string $eventId, string $error, int $attempt, ?array $redelivery): void {
    $now = $this->stamp();
    $this->write(
      "INSERT INTO `{$this->table()}` (subscriber_key, subscriber_id, event_id, status, attempts, last_error, redelivery, created_at, updated_at)
       VALUES (%s, %s, %s, 'failed', %d, %s, " . ($redelivery === null ? 'NULL' : '%s') . ", %s, %s)
       ON DUPLICATE KEY UPDATE
         attempts = IF(status = 'delivered', attempts, VALUES(attempts)),
         last_error = IF(status = 'delivered', last_error, VALUES(last_error)),
         redelivery = COALESCE(VALUES(redelivery), redelivery),
         updated_at = VALUES(updated_at)",
      array_merge(
        [sha1($subscriberId), $subscriberId, $eventId, $attempt, $error],
        $redelivery === null ? [] : [(string) wp_json_encode($redelivery)],
        [$now, $now]
      ),
      'mark_failed'
    );
  }

  /**
   * Facts with at least one `failed` subscriber and known redelivery args,
   * oldest first: one row per event_id with the highest attempt count and
   * the latest failure time (the next redelivery is due at
   * updated_at + backoff(attempts)).
   *
   * @return list<array{event_id: string, attempts: int, updated_at: string, redelivery: array<string, mixed>}>
   */
  public function restorable(int $limit = 100): array {
    $db = self::db();
    $rows = $db->get_results($db->prepare(
      "SELECT event_id, MAX(attempts) AS attempts, MAX(updated_at) AS updated_at, MIN(id) AS id FROM `{$this->table()}`
       WHERE status = 'failed' AND redelivery IS NOT NULL GROUP BY event_id ORDER BY MAX(updated_at) ASC LIMIT %d",
      max(0, $limit)
    ), ARRAY_A);
    if ($db->last_error !== '') {
      throw new \RuntimeException("Delivery ledger read failed: {$db->last_error}");
    }
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $r) {
      $args = json_decode((string) $db->get_var($db->prepare("SELECT redelivery FROM `{$this->table()}` WHERE id = %d", (int) $r['id'])), true);
      if (!is_array($args) || !isset($args['hook'], $args['event_class'], $args['payload'])) {
        continue;
      }
      $out[] = ['event_id' => (string) $r['event_id'], 'attempts' => (int) $r['attempts'], 'updated_at' => (string) $r['updated_at'], 'redelivery' => $args];
    }
    return $out;
  }

  public function attempts(string $subscriberId, string $eventId): int {
    return (int) $this->column('attempts', $subscriberId, $eventId);
  }

  public function last_error(string $subscriberId, string $eventId): ?string {
    $v = $this->column('last_error', $subscriberId, $eventId);
    return $v === null ? null : (string) $v;
  }

  public function mark_exhausted(string $subscriberId, string $eventId): void {
    $now = $this->stamp();
    $this->write(
      "INSERT INTO `{$this->table()}` (subscriber_key, subscriber_id, event_id, status, attempts, exhausted_at, created_at, updated_at)
       VALUES (%s, %s, %s, 'exhausted', 0, %s, %s, %s)
       ON DUPLICATE KEY UPDATE status = IF(status = 'delivered', status, 'exhausted'),
         exhausted_at = COALESCE(exhausted_at, VALUES(exhausted_at)), updated_at = VALUES(updated_at)",
      [sha1($subscriberId), $subscriberId, $eventId, $now, $now, $now],
      'mark_exhausted'
    );
  }

  public function exhausted(string $subscriberId, string $eventId): bool {
    return $this->status($subscriberId, $eventId) === 'exhausted';
  }

  /** mark_exhausted() that also records why in last_error (no compensation ran, or an operator abandoned it). */
  public function mark_exhausted_because(string $subscriberId, string $eventId, string $reason): void {
    $now = $this->stamp();
    $this->write(
      "INSERT INTO `{$this->table()}` (subscriber_key, subscriber_id, event_id, status, attempts, last_error, exhausted_at, created_at, updated_at)
       VALUES (%s, %s, %s, 'exhausted', 0, %s, %s, %s, %s)
       ON DUPLICATE KEY UPDATE
         last_error = IF(status = 'delivered', last_error, VALUES(last_error)),
         status = IF(status = 'delivered', status, 'exhausted'),
         exhausted_at = COALESCE(exhausted_at, VALUES(exhausted_at)), updated_at = VALUES(updated_at)",
      [sha1($subscriberId), $subscriberId, $eventId, $reason, $now, $now, $now],
      'mark_exhausted'
    );
  }

  /**
   * Operator repair (`wp ddd ops --abandon=<pair>`): a `failed`
   * pair becomes terminal (`exhausted`, the reason in last_error) without a
   * compensation; its pending redelivery then skips it.
   *
   * @return bool false when the pair is not `failed`
   */
  public function abandon(string $subscriberId, string $eventId, string $reason): bool {
    $db = self::db();
    $now = $this->stamp();
    $n = $db->query($db->prepare(
      "UPDATE `{$this->table()}` SET status = 'exhausted', exhausted_at = %s, last_error = %s, updated_at = %s
       WHERE subscriber_key = %s AND event_id = %s AND status = 'failed'",
      $now, $reason, $now, sha1($subscriberId), $eventId
    ));
    if ($n === false) {
      throw new \RuntimeException('Delivery ledger abandon failed: ' . (string) $db->last_error);
    }
    return (int) $n === 1;
  }

  /**
   * The `failed` subscribers of one fact: subscriber id => attempts.
   *
   * @return array<string, int>
   */
  public function failures(string $eventId): array {
    $db = self::db();
    $rows = $db->get_results($db->prepare(
      "SELECT subscriber_id, attempts FROM `{$this->table()}` WHERE event_id = %s AND status = 'failed' ORDER BY id ASC",
      $eventId
    ), ARRAY_A);
    if ($db->last_error !== '') {
      throw new \RuntimeException("Delivery ledger read failed: {$db->last_error}");
    }
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $r) {
      $out[(string) $r['subscriber_id']] = (int) $r['attempts'];
    }
    return $out;
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
