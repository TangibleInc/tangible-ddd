<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Runtime\Outbox\OutboxWriteFailed;

/**
 * Transitional wave-2 IOutboxStore on WordPress (register 3.4, section 8):
 * the port over the 0.6 OutboxRepository and schema, UNFENCED until schema
 * v8 adds `claim_token` (wave 3).
 *
 * - append(): one 0.6 row from the record, with `scheduled_at` = the
 *   record's absolute UTC due_at and `delay_seconds` 0 (bug 3: no relative
 *   delay is stored, so no publisher can add it again, including a rolled
 *   back 0.6 one). An is_unique record first runs the repository's 0.6
 *   cancel_duplicates() (event-type match; the signature match of the port
 *   contract is wave 3). A failed insert throws OutboxWriteFailed.
 * - claim(): the repository's fetch_pending() (FOR UPDATE SKIP LOCKED, pause
 *   aware, sets locked_until/locked_by so a 0.6 copy keeps excluding the
 *   rows). The lease is the repository's fixed 300 s, not $leaseSeconds; the
 *   claim token is the worker id written to locked_by. Refused inside an
 *   open transaction (NestedTransactionRejected).
 * - accept() → mark_completed() (status `completed`, R4); retryLater() →
 *   mark_failed() (its own backoff; $nextAt is not honoured); deadLetter() →
 *   move_to_dlq(). All return true: without a claim token the lease cannot
 *   be checked.
 */
final class WpdbOutboxStore implements IOutboxStore {

  public function __construct(
    private readonly OutboxRepository $repository,
    private readonly IDDDConfig $config,
  ) {}

  public function append(OutboxRecord $r): void {
    if ($r->is_unique) {
      $this->repository->cancel_duplicates($r->event_type, $r->payload_signature ?? $r->payload);
    }

    $db = $GLOBALS['wpdb'];
    $payload = json_encode($r->payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
      throw new OutboxWriteFailed("Outbox payload of {$r->event_id} is not JSON-encodable: " . json_last_error_msg());
    }

    $ok = $db->insert($this->config->table('integration_outbox'), [
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
      'created_at' => gmdate('Y-m-d H:i:s'),
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

    $worker = gethostname() . '-' . getmypid();
    return array_map(
      static fn (OutboxEntry $e) => new Claim(
        $e->event_id,
        (string) ($e->locked_by ?? $worker),
        new \DateTimeImmutable((string) ($e->locked_until ?? 'now'), new \DateTimeZone('UTC')),
        new OutboxRecord(
          $e->event_id,
          $e->event_type,
          $e->integration_action,
          $e->correlation_id,
          $e->sequence,
          $e->command_id,
          $e->payload,
          new \DateTimeImmutable($e->scheduled_at, new \DateTimeZone('UTC')),
          $e->is_unique,
          null,
          $e->max_attempts,
          $e->blog_id,
        ),
        $e->attempts,
      ),
      $this->repository->fetch_pending($limit, $worker)
    );
  }

  public function accept(Claim $c, ?string $transportRef): bool {
    $this->repository->mark_completed($c->event_id);
    return true;
  }

  public function retryLater(Claim $c, string $error, \DateTimeImmutable $nextAt): bool {
    $this->repository->mark_failed($c->event_id, $error);
    return true;
  }

  public function deadLetter(Claim $c, string $error): bool {
    $this->repository->move_to_dlq($c->event_id, $error);
    return true;
  }
}
