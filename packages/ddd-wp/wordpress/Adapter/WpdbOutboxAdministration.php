<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Support\ConsumerTables;
use TangibleDDD\Runtime\Outbox\DeadLetter;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxRowIds;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationRefused;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\SystemClock;

/**
 * The wp IOutboxAdministration (register 3.4, 1.4 "4 repair handlers"),
 * on the 0.6 schema and on v8 (a reset also clears `claim_token`; time is
 * the host IClock, WPC-3), for one consumer prefix
 * (`{wp_prefix}{prefix}_integration_outbox` / `_integration_dlq`, the
 * tables ConsumerTables names; the consumer need not be registered).
 *
 * - retry(event_id): refuses a LEASED row (`locked_until` in the future)
 *   always, and a row that is not `pending`/`dlq` unless forced (O5); resets
 *   status `pending`, attempts 0, next attempt now, clears the lock and the
 *   error.
 * - replay(dlq id): KEEPS the event_id (C22): resets the original outbox row
 *   (left in place with status `dlq` by move_to_dlq) or re-inserts it with
 *   the original event_id when it was purged, then deletes the DLQ row, in
 *   one transaction. (0.6 minted a fresh UUID; the 0.6.7 hotfix still does.)
 * - discard(dlq id): deletes the DLQ row.
 * - purge(older than): deletes `completed` rows processed before the cutoff
 *   (wp keeps writing `completed`; `accepted` is the port's read alias).
 * - stats(): counts by port status (`completed` reported as `accepted`) plus
 *   `dead_letters` (no `resolved_at` column is read, C24).
 * - event_id_of(int): IOutboxRowIds, for RetryDeliveryCommand's integer id.
 *
 * Transactions: replay runs in the repair command's transaction when one is
 * open (the self-consumer bus), else in its own (WpdbTransactionBoundary).
 * Errors: OutboxRowNotFound, OutboxAdministrationRefused; a wpdb failure
 * (false) throws OutboxWriteFailed with `$wpdb->last_error`.
 */
final class WpdbOutboxAdministration implements IOutboxAdministration, IOutboxRowIds {

  /** @param IClock|null $clock the host clock (else HostDefaults, else SystemClock); WPC-3 */
  public function __construct(
    private readonly string $prefix,
    private readonly ?IClock $clock = null,
  ) {}

  private function now(): \DateTimeImmutable {
    return ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now()->setTimezone(new \DateTimeZone('UTC'));
  }

  public function dead_letters(int $limit, ?string $after = null): array {
    $db = self::db();
    $rows = $db->get_results($db->prepare(
      "SELECT * FROM `{$this->dlq()}` WHERE id > %d ORDER BY id ASC LIMIT %d",
      (int) ($after ?? 0),
      max(0, $limit)
    ));

    return array_map(fn (object $row) => new DeadLetter(
      (int) $row->id,
      (string) $row->event_id,
      (string) ($row->final_error ?? ''),
      (int) ($row->attempts ?? 0),
      self::utc((string) ($row->moved_at ?? 'now')),
      $this->record_from_dlq($row),
    ), is_array($rows) ? $rows : []);
  }

  public function retry(string $event_id, bool $force = false): void {
    $db = self::db();
    $row = $db->get_row($db->prepare("SELECT * FROM `{$this->outbox()}` WHERE event_id = %s", $event_id));
    if (!$row) {
      throw new OutboxRowNotFound("Outbox row $event_id not found in {$this->outbox()}");
    }

    if (!empty($row->locked_until) && self::utc((string) $row->locked_until) > $this->now()) {
      throw new OutboxAdministrationRefused("Outbox row $event_id is leased until {$row->locked_until}; retry refused");
    }
    if (!$force && !in_array($row->status, ['pending', 'dlq'], true)) {
      throw new OutboxAdministrationRefused("Outbox row $event_id is {$row->status}; retry needs force");
    }

    $this->reset_row($event_id);
  }

  public function replay(int $dlqId): void {
    $db = self::db();
    $letter = $db->get_row($db->prepare("SELECT * FROM `{$this->dlq()}` WHERE id = %d", $dlqId));
    if (!$letter) {
      throw new OutboxRowNotFound("Dead-letter #$dlqId not found in {$this->dlq()}");
    }

    $this->atomically(function () use ($db, $letter, $dlqId): void {
      $exists = $db->get_var($db->prepare("SELECT 1 FROM `{$this->outbox()}` WHERE event_id = %s", $letter->event_id));
      if ($exists) {
        $this->reset_row((string) $letter->event_id);
      } else {
        $now = $this->now()->format('Y-m-d H:i:s');
        $this->checked($db->insert($this->outbox(), [
          'event_id' => $letter->event_id,
          'event_type' => $letter->event_type,
          'integration_action' => $letter->integration_action,
          'message_kind' => 'event',
          'transport' => 'action_scheduler',
          'queue' => null,
          'payload_bytes' => strlen((string) $letter->payload),
          'correlation_id' => $letter->correlation_id,
          'sequence' => 0,
          'command_id' => $letter->command_id,
          'payload' => $letter->payload,
          'delay_seconds' => 0,
          'scheduled_at' => $now,
          'is_unique' => 0,
          'status' => 'pending',
          'attempts' => 0,
          'max_attempts' => 5,
          'next_attempt_at' => $now,
          'created_at' => $now,
          'blog_id' => $letter->blog_id ?? 1,
        ]), 're-insert the replayed outbox row');
      }
      $this->checked($db->delete($this->dlq(), ['id' => $dlqId]), 'delete the replayed dead-letter');
    });
  }

  public function discard(int $dlqId): void {
    $db = self::db();
    $deleted = $db->delete($this->dlq(), ['id' => $dlqId]);
    $this->checked($deleted, 'discard the dead-letter');
    if ((int) $deleted === 0) {
      throw new OutboxRowNotFound("Dead-letter #$dlqId not found in {$this->dlq()}");
    }
  }

  public function purge(\DateTimeImmutable $olderThan): int {
    $db = self::db();
    $result = $db->query($db->prepare(
      "DELETE FROM `{$this->outbox()}` WHERE status = 'completed' AND processed_at IS NOT NULL AND processed_at < %s",
      $olderThan->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
    ));
    $this->checked($result, 'purge the outbox');
    return (int) $result;
  }

  public function stats(): array {
    $db = self::db();
    $stats = ['pending' => 0, 'accepted' => 0, 'dlq' => 0, 'cancelled' => 0];
    foreach ((array) $db->get_results("SELECT status, COUNT(*) AS count FROM `{$this->outbox()}` GROUP BY status") as $row) {
      $status = $row->status === 'completed' ? 'accepted' : (string) $row->status;
      $stats[$status] = ($stats[$status] ?? 0) + (int) $row->count;
    }
    $stats['dead_letters'] = (int) $db->get_var("SELECT COUNT(*) FROM `{$this->dlq()}`");
    return $stats;
  }

  public function event_id_of(int $outboxId): ?string {
    $db = self::db();
    $id = $db->get_var($db->prepare("SELECT event_id FROM `{$this->outbox()}` WHERE id = %d", $outboxId));
    return $id === null ? null : (string) $id;
  }

  private function reset_row(string $event_id): void {
    $db = self::db();
    $reset = [
      'status' => 'pending',
      'attempts' => 0,
      'next_attempt_at' => $this->now()->format('Y-m-d H:i:s'),
      'locked_until' => null,
      'locked_by' => null,
      'last_error' => null,
    ];
    if (WpSchema::is_v8($this->prefix)) {
      $reset['claim_token'] = null; // schema v8: a stale token must fence nothing
    }
    $this->checked($db->update($this->outbox(), $reset, ['event_id' => $event_id]), "reset outbox row $event_id");
  }

  private function record_from_dlq(object $row): OutboxRecord {
    $payload = json_decode((string) ($row->payload ?? ''), true);
    return new OutboxRecord(
      (string) $row->event_id,
      (string) $row->event_type,
      (string) $row->integration_action,
      $row->correlation_id ?? null,
      null,
      $row->command_id ?? null,
      is_array($payload) ? $payload : [],
      self::utc((string) ($row->moved_at ?? 'now')),
      blog_id: isset($row->blog_id) ? (int) $row->blog_id : null,
    );
  }

  private function atomically(callable $work): void {
    if (WpdbTransactionDepth::current() > 0) {
      $work();
      return;
    }
    (new WpdbTransactionBoundary(NestedPolicy::Reject))->run($work);
  }

  private function checked(mixed $result, string $what): void {
    if ($result === false) {
      throw new OutboxWriteFailed("Failed to $what: " . (string) self::db()->last_error);
    }
  }

  private function outbox(): string {
    return ConsumerTables::name($this->prefix, 'integration_outbox');
  }

  private function dlq(): string {
    return ConsumerTables::name($this->prefix, 'integration_dlq');
  }

  private static function utc(string $time): \DateTimeImmutable {
    return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
  }

  private static function db(): \wpdb {
    return $GLOBALS['wpdb'];
  }
}
