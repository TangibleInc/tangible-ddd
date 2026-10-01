<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Runtime\Support\Log;

/**
 * IOutboxStore over a consumer's own 0.6 IOutboxRepository (register 3.4,
 * R3: the legacy interface gains no methods; consumers such as the LMS
 * Doctrine repository are bridged instead). DEGRADED and UNFENCED:
 *
 * - claim(): release_stale_locks($leaseSeconds), then
 *   fetch_pending($limit, $workerId); the repository leases rows its own
 *   way and honours its own pauses and clock ($now only computes the
 *   reported leaseUntil). Each entry becomes a Claim whose record carries
 *   the entry's ABSOLUTE scheduled_at as due_at, read as UTC: a 0.6 row with
 *   delay_seconds > 0 is not delayed a second time (bug 3, extraction
 *   variant).
 * - accept() → mark_completed(), retryLater() → mark_failed() (the
 *   repository computes its own next attempt; $nextAt is not honoured),
 *   deadLetter() → move_to_dlq(). All three return true: there is no claim
 *   token to fence on, so a late holder's write is NOT detected. The
 *   missing fence is logged once per instance as a warning.
 * - append() throws OutboxWriteFailed: IOutboxRepository::write() takes the
 *   event and mints its own event_id, so a port record cannot be appended
 *   with its identity. Such consumers publish through the 0.6 bus form.
 *
 * Errors from the repository propagate unchanged. Lifetime: stateless per
 * call apart from the one-time warning.
 */
final class LegacyOutboxStore implements IOutboxStore {

  private bool $warned = false;

  private readonly string $workerId;

  public function __construct(
    private readonly IOutboxRepository $repository,
    private readonly ?LoggerInterface $logger = null,
    ?string $workerId = null,
  ) {
    $this->workerId = $workerId ?? (gethostname() . '-' . getmypid());
  }

  public function repository(): IOutboxRepository {
    return $this->repository;
  }

  public function append(OutboxRecord $r): void {
    throw new OutboxWriteFailed(sprintf(
      'LegacyOutboxStore cannot append %s: %s::write() mints its own event_id; publish through the 0.6 bus form.',
      $r->event_id, get_class($this->repository)
    ));
  }

  public function claim(int $limit, \DateTimeImmutable $now, int $leaseSeconds): array {
    $this->warnOnce();

    $this->repository->release_stale_locks($leaseSeconds);
    $entries = $this->repository->fetch_pending($limit, $this->workerId);

    $leaseUntil = $now->modify("+{$leaseSeconds} seconds");
    $claims = [];
    foreach ($entries as $entry) {
      $claims[] = new Claim($entry->event_id, 'legacy:' . $this->workerId, $leaseUntil, self::record($entry), $entry->attempts);
    }
    return $claims;
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

  /** The port record of a 0.6 entry; due_at is the absolute scheduled_at, read as UTC. */
  public static function record(OutboxEntry $entry): OutboxRecord {
    $utc = new \DateTimeZone('UTC');
    $due = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $entry->scheduled_at, $utc)
      ?: new \DateTimeImmutable($entry->scheduled_at !== '' ? $entry->scheduled_at : 'now', $utc);

    return new OutboxRecord(
      $entry->event_id,
      $entry->event_type,
      $entry->integration_action,
      $entry->correlation_id,
      $entry->sequence,
      $entry->command_id,
      $entry->payload,
      $due,
      $entry->is_unique,
      null,
      $entry->max_attempts,
      $entry->blog_id,
    );
  }

  private function warnOnce(): void {
    if ($this->warned) {
      return;
    }
    $this->warned = true;
    Log::write($this->logger, sprintf(
      '[ddd outbox] LegacyOutboxStore over %s is unfenced: a late lease holder\'s accept/retry/dead-letter is not detected (register 3.4, R3)',
      get_class($this->repository)
    ));
  }
}
