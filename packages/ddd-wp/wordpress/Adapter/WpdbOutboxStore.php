<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Outbox\IReportsClaimDeadLetters;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;
use TangibleDDD\Runtime\SystemClock;

/**
 * The wp IOutboxStore on schema v8 (register 3.4; rulings on statuses;
 * WPC-3), fenced by `claim_token`, over `{prefix}_integration_outbox` and
 * `{prefix}_integration_dlq` on the global `$wpdb`.
 *
 * - append(): one row from the record, `scheduled_at` = the record's
 *   absolute UTC due_at and `delay_seconds` 0 (bug 3: no relative delay is
 *   stored, so no publisher can add it again, a rolled-back 0.6 one
 *   included). An is_unique record first cancels the UNLEASED `pending`
 *   rows with the same event type and payload signature (C26, O6). A failed
 *   insert throws OutboxWriteFailed.
 * - claim($limit, $now, $leaseSeconds): its own short transaction
 *   (NestedTransactionRejected inside an open one), `SELECT … FOR UPDATE
 *   SKIP LOCKED` of due (`scheduled_at <= $now`, `next_attempt_at <= $now`),
 *   lease-free, unpaused rows oldest first, then ONE update that sets a
 *   fresh per-row `claim_token` AND the 0.6 lock columns `locked_until`
 *   (= $now + $leaseSeconds) / `locked_by` (the worker), so a 0.6 copy
 *   running alongside during a deploy keeps excluding claimed rows. Time is
 *   the caller's $now, never the wall clock.
 * - CR-PDO-6 (IReportsClaimDeadLetters): a selected row that still carries
 *   a claim_token is a re-claim of an expired N lease (every outcome clears
 *   the token): it counts one attempt (`attempts + 1`, `last_error` =
 *   LEASE_EXPIRED_ERROR, an error_history entry), and Claim::$attempts
 *   includes it. When that reaches max_attempts the row is dead-lettered
 *   inside the claim's transaction (status `dlq`, lease cleared, DLQ row)
 *   and returned by take_claim_dead_letters(), not handed out. A row a 0.6
 *   copy leased (locked_until without claim_token) is not counted: 0.6
 *   counts its own attempts.
 * - accept() writes status `completed` (the 0.6 ENUM value; `accepted` is
 *   the port's read alias, so a rolled-back 0.6 winner still purges and
 *   counts them); retry_later() attempts + 1 in SQL, `next_attempt_at` =
 *   $nextAt, the error appended to `error_history`; dead_letter() counts the
 *   final attempt and inserts the DLQ row in one transaction. All three are
 *   fenced `WHERE event_id = ? AND claim_token = ?`: 0 rows = lease lost,
 *   false, nothing written.
 *
 * Time for the rows' own stamps (created_at, processed_at, moved_at) is the
 * host IClock (constructor, else HostDefaults, else SystemClock).
 *
 * Pauses: a WpRelayPauseStore (default) is applied in the claim's SQL; any
 * other IRelayPauseStore filters the selected rows before they are leased.
 *
 * The OutboxRepository argument is kept for the wave-2 constructor shape
 * (the conformance host and HostDefaults pass it); the v8 store does not
 * delegate to it.
 */
final class WpdbOutboxStore implements IOutboxStore, IReportsClaimDeadLetters {

  private readonly IRelayPauseStore $pauses;

  /** @var list<array{0: Claim, 1: string}> claim-time dead letters not yet taken by the relay step */
  private array $claim_dead_letters = [];

  public function __construct(
    OutboxRepository $repository,
    private readonly IDDDConfig $config,
    private readonly ?IClock $clock = null,
    ?IRelayPauseStore $pauses = null,
  ) {
    $this->pauses = $pauses ?? new WpRelayPauseStore($config, $clock);
  }

  public function append(OutboxRecord $r): void {
    $db = self::db();
    $payload = json_encode($r->payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
      throw new OutboxWriteFailed("Outbox payload of {$r->event_id} is not JSON-encodable: " . json_last_error_msg());
    }
    $now = $this->stamp();

    if ($r->is_unique) {
      $signature = json_encode($r->payload_signature ?? $r->payload, JSON_UNESCAPED_SLASHES);
      // Before the v8 migration ran there is no claim_token column; the
      // 0.6 lock column alone marks a leased row then.
      $unclaimed = WpSchema::is_v8($this->config) ? 'claim_token IS NULL AND ' : '';
      $cancelled = $db->query($db->prepare(
        "UPDATE `{$this->outbox()}` SET status = 'cancelled'
         WHERE event_type = %s AND status = 'pending' AND is_unique = 1
           AND {$unclaimed}(locked_until IS NULL OR locked_until <= %s)
           AND payload = CAST(%s AS JSON)",
        $r->event_type,
        $now,
        (string) $signature
      ));
      if ($cancelled === false) {
        throw new OutboxWriteFailed("Cancelling duplicates of {$r->event_id} failed: " . (string) $db->last_error);
      }
    }

    $ok = $db->insert($this->outbox(), [
      'event_id' => $r->event_id,
      'event_type' => $r->event_type,
      'integration_action' => $r->integration_action,
      'message_kind' => 'event',
      'transport' => 'action_scheduler',
      'queue' => $this->config->prefix() . '-outbox',
      'payload_bytes' => strlen($payload),
      'correlation_id' => $r->correlation_id,
      'sequence' => $r->sequence ?? 1,
      'command_id' => $r->command_id,
      'payload' => $payload,
      'delay_seconds' => 0,
      'scheduled_at' => $r->due_at->format('Y-m-d H:i:s'),
      'is_unique' => $r->is_unique ? 1 : 0,
      'status' => 'pending',
      'attempts' => 0,
      'max_attempts' => $r->max_attempts,
      'created_at' => $now,
      'blog_id' => $r->blog_id ?? (is_multisite() ? get_current_blog_id() : 1),
    ]);

    if ($ok === false) {
      throw new OutboxWriteFailed("Outbox insert of {$r->event_id} failed: " . (string) $db->last_error);
    }
  }

  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
    if (WpdbTransactionDepth::current() > 0) {
      throw new NestedTransactionRejected('IOutboxStore::claim() runs its own transaction and must be called outside one.');
    }
    if ($limit <= 0) {
      return [];
    }

    $at = $now->setTimezone(new \DateTimeZone('UTC'));
    [$exclude, $params] = $this->pauses instanceof WpRelayPauseStore ? $this->pauses->exclusion($at) : ['', []];
    if ($exclude === null) {
      return []; // a wildcard hold pauses everything
    }

    $leaseUntil = $at->modify('+' . max(0, $leaseSeconds) . ' seconds');
    $worker = substr(gethostname() . '-' . getmypid(), 0, 64);

    return (new WpdbTransactionBoundary(NestedPolicy::Reject))->run(function () use ($limit, $at, $exclude, $params, $leaseUntil, $worker): array {
      $db = self::db();
      $stamp = $at->format('Y-m-d H:i:s');
      $rows = $db->get_results($db->prepare(
        "SELECT * FROM `{$this->outbox()}`
         WHERE status = 'pending'
           AND scheduled_at <= %s
           AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
           AND (locked_until IS NULL OR locked_until <= %s)
           $exclude
         ORDER BY scheduled_at ASC, id ASC
         LIMIT %d
         FOR UPDATE SKIP LOCKED",
        ...[$stamp, $stamp, $stamp, ...$params, $limit]
      ));
      // wpdb::get_results() returns [] (not null) on a query error: the error
      // is only in last_error, which get_results() resets on success.
      if ($db->last_error !== '') {
        throw new OutboxWriteFailed('Outbox claim failed: ' . (string) $db->last_error);
      }

      $claims = [];
      foreach (is_array($rows) ? $rows : [] as $row) {
        if (!$this->pauses instanceof WpRelayPauseStore && $this->pauses->is_paused((string) $row->event_type, $at)) {
          continue;
        }
        $token = bin2hex(random_bytes(16));
        // CR-PDO-6: a row that still carries a claim_token was claimed by N
        // and its holder died without an outcome (accept / retry_later /
        // dead_letter all clear the token). Its lease is expired (the SELECT
        // only takes lease-free rows), so this re-claim is one attempt.
        $reclaim = $row->claim_token !== null && $row->claim_token !== '';
        $ok = $db->query($reclaim
          ? $db->prepare(
            // error_history is assigned before attempts, so it reads the old count.
            "UPDATE `{$this->outbox()}`
             SET error_history = JSON_ARRAY_APPEND(COALESCE(error_history, JSON_ARRAY()), '$', JSON_OBJECT('attempt', attempts + 1, 'error', %s, 'timestamp', %s)),
                 attempts = attempts + 1, last_error = %s,
                 claim_token = %s, locked_until = %s, locked_by = %s
             WHERE id = %d",
            self::LEASE_EXPIRED_ERROR,
            $stamp,
            self::LEASE_EXPIRED_ERROR,
            $token,
            $leaseUntil->format('Y-m-d H:i:s'),
            $worker,
            (int) $row->id
          )
          : $db->prepare(
            "UPDATE `{$this->outbox()}` SET claim_token = %s, locked_until = %s, locked_by = %s WHERE id = %d",
            $token,
            $leaseUntil->format('Y-m-d H:i:s'),
            $worker,
            (int) $row->id
          ));
        if ($ok === false) {
          throw new OutboxWriteFailed("Outbox claim of {$row->event_id} failed: " . (string) $db->last_error);
        }
        $attempts = (int) $row->attempts + ($reclaim ? 1 : 0);
        $claim = new Claim((string) $row->event_id, $token, $leaseUntil, self::record($row), $attempts);
        if ($reclaim && $attempts >= (int) $row->max_attempts) {
          $error = sprintf('%s %d times; dead-lettered at claim', self::LEASE_EXPIRED_ERROR, $attempts);
          $this->deadLetterAtClaim($claim, $error, $stamp);
          $this->claim_dead_letters[] = [$claim, $error];
          continue;
        }
        $claims[] = $claim;
      }
      return $claims;
    });
  }

  public function take_claim_dead_letters(): array {
    $taken = $this->claim_dead_letters;
    $this->claim_dead_letters = [];
    return $taken;
  }

  /** Inside claim()'s transaction: the re-claimed row goes to the DLQ instead of being handed out. */
  private function deadLetterAtClaim(Claim $c, string $error, string $stamp): void {
    $db = self::db();
    $ok = $db->query($db->prepare(
      "UPDATE `{$this->outbox()}`
       SET status = 'dlq', last_error = %s, locked_until = NULL, locked_by = NULL, claim_token = NULL
       WHERE event_id = %s AND claim_token = %s",
      $error,
      $c->event_id,
      $c->token
    ));
    if ($ok === false || (int) $ok !== 1) {
      throw new OutboxWriteFailed("Dead-lettering {$c->event_id} at claim failed: " . (string) $db->last_error);
    }
    $ok = $db->query($db->prepare(
      "INSERT INTO `{$this->dlq()}`
         (outbox_id, event_id, event_type, integration_action, correlation_id, command_id, payload, attempts, error_history, final_error, moved_at, blog_id)
       SELECT id, event_id, event_type, integration_action, correlation_id, command_id, payload, attempts, error_history, %s, %s, blog_id
       FROM `{$this->outbox()}` WHERE event_id = %s",
      $error,
      $stamp,
      $c->event_id
    ));
    if ($ok === false) {
      throw new OutboxWriteFailed("Dead-letter insert of {$c->event_id} at claim failed: " . (string) $db->last_error);
    }
  }

  public function accept(Claim $c, ?string $transportRef): bool {
    $db = self::db();
    $n = $db->query($db->prepare(
      "UPDATE `{$this->outbox()}`
       SET status = 'completed', processed_at = %s, locked_until = NULL, locked_by = NULL, claim_token = NULL
       WHERE event_id = %s AND claim_token = %s",
      $this->stamp(),
      $c->event_id,
      $c->token
    ));
    return $this->fenced($n, 'accept', $c);
  }

  public function retry_later(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
    $db = self::db();
    // error_history is assigned before attempts, so it reads the old count.
    $n = $db->query($db->prepare(
      "UPDATE `{$this->outbox()}`
       SET error_history = JSON_ARRAY_APPEND(COALESCE(error_history, JSON_ARRAY()), '$', JSON_OBJECT('attempt', attempts + 1, 'error', %s, 'timestamp', %s)),
           attempts = attempts + 1, last_error = %s, next_attempt_at = %s, status = 'pending',
           locked_until = NULL, locked_by = NULL, claim_token = NULL
       WHERE event_id = %s AND claim_token = %s",
      $error,
      $this->stamp(),
      $error,
      $nextAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
      $c->event_id,
      $c->token
    ));
    return $this->fenced($n, 'retry_later', $c);
  }

  public function dead_letter(Claim $c, string $error): bool {
    return $this->atomically(function () use ($c, $error): bool {
      $db = self::db();
      $n = $db->query($db->prepare(
        "UPDATE `{$this->outbox()}`
         SET attempts = attempts + 1, last_error = %s, status = 'dlq',
             locked_until = NULL, locked_by = NULL, claim_token = NULL
         WHERE event_id = %s AND claim_token = %s",
        $error,
        $c->event_id,
        $c->token
      ));
      if (!$this->fenced($n, 'dead_letter', $c)) {
        return false;
      }

      $ok = $db->query($db->prepare(
        "INSERT INTO `{$this->dlq()}`
           (outbox_id, event_id, event_type, integration_action, correlation_id, command_id, payload, attempts, error_history, final_error, moved_at, blog_id)
         SELECT id, event_id, event_type, integration_action, correlation_id, command_id, payload, attempts, error_history, %s, %s, blog_id
         FROM `{$this->outbox()}` WHERE event_id = %s",
        $error,
        $this->stamp(),
        $c->event_id
      ));
      if ($ok === false) {
        throw new OutboxWriteFailed("Dead-letter insert of {$c->event_id} failed: " . (string) $db->last_error);
      }
      return true;
    });
  }

  private function fenced(mixed $affected, string $what, Claim $c): bool {
    if ($affected === false) {
      throw new OutboxWriteFailed("Outbox $what of {$c->event_id} failed: " . (string) self::db()->last_error);
    }
    return (int) $affected === 1;
  }

  /**
   * @template T
   * @param callable():T $work
   * @return T
   */
  private function atomically(callable $work): mixed {
    if (WpdbTransactionDepth::current() > 0) {
      return $work();
    }
    return (new WpdbTransactionBoundary(NestedPolicy::Reject))->run($work);
  }

  private static function record(object $row): OutboxRecord {
    $payload = json_decode((string) $row->payload, true);
    return new OutboxRecord(
      (string) $row->event_id,
      (string) $row->event_type,
      (string) $row->integration_action,
      $row->correlation_id ?? null,
      isset($row->sequence) ? (int) $row->sequence : null,
      $row->command_id ?? null,
      is_array($payload) ? $payload : [],
      new \DateTimeImmutable((string) $row->scheduled_at, new \DateTimeZone('UTC')),
      (bool) $row->is_unique,
      null,
      (int) $row->max_attempts,
      isset($row->blog_id) ? (int) $row->blog_id : null,
    );
  }

  private function stamp(): string {
    return ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now()
      ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  }

  private function outbox(): string {
    return $this->config->table('integration_outbox');
  }

  private function dlq(): string {
    return $this->config->table('integration_dlq');
  }

  private static function db(): \wpdb {
    return $GLOBALS['wpdb'];
  }
}
