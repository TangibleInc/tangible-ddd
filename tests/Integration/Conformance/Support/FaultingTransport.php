<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Conformance\Support\RecordingOutboxStore;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\WordPress\Adapter\ActionSchedulerTransport;

/**
 * The shipped wp transport (ActionSchedulerTransport, WPC-1) with the two
 * one-shot fault seams the relay scenarios need, and nothing else:
 *
 * - rejectNext(): the next submit() throws (TransportRejected by default);
 * - noReferenceNext(): the next submit() stores nothing and returns '0',
 *   exactly what the real transport returns when Action Scheduler could not
 *   store the action (the relay treats it as a rejection, CONF-4).
 *
 * shares_connection() asks the real transport about the store under a
 * conformance RecordingOutboxStore, so the relay still runs submit + accept
 * in one wpdb transaction.
 */
final class FaultingTransport implements ITransport {

  private ?\Throwable $rejectNext = null;

  private bool $noReferenceNext = false;

  public function __construct(
    private readonly ActionSchedulerTransport $inner,
    public readonly string $group,
  ) {}

  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
    if ($this->rejectNext !== null) {
      $e = $this->rejectNext;
      $this->rejectNext = null;
      throw $e;
    }
    if ($this->noReferenceNext) {
      $this->noReferenceNext = false;
      return '0';
    }
    return $this->inner->submit($c, $wrappedEnvelope, $dueAt);
  }

  public function shares_connection(IOutboxStore $store): bool {
    while ($store instanceof RecordingOutboxStore) {
      $store = $store->inner();
    }
    return $this->inner->shares_connection($store);
  }

  public function rejectNext(?\Throwable $e = null): void {
    $this->rejectNext = $e ?? new TransportRejected('Action Scheduler rejected the submission (injected)');
  }

  public function noReferenceNext(): void {
    $this->noReferenceNext = true;
  }
}
