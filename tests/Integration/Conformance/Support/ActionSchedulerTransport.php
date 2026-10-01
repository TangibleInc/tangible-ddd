<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\WordPress\Adapter\WpdbOutboxStore;

/**
 * The wp ITransport for the conformance host: Action Scheduler on the
 * WordPress connection, with the 0.6 action shape (hook = the fact's
 * integration_action, args = [wrapped envelope], group = the consumer's
 * outbox queue). ddd-wp ships no ITransport in wave 2 (its relay still uses
 * the 0.6 IOutboxPublisher, which returns no reference); change request
 * WPC-1 asks ddd-wp to ship this as TangibleDDD\WordPress\Adapter\ActionSchedulerTransport.
 *
 * - submit() schedules a single action at the ABSOLUTE $dueAt, also when
 *   that is already past (Action Scheduler runs past-due actions at once),
 *   so the transport keeps the one due time and never adds a delay (bug 3).
 *   The action id is the reference; an id of 0 (AS could not store it) is
 *   returned as '0', which the relay treats as a rejection (CONF-4).
 * - sharesConnectionWith(): true for the wpdb outbox store, since both
 *   write through the global $wpdb; the relay then runs submit + accept in
 *   one WpdbTransactionBoundary transaction.
 * - Fault seams for the scenarios: rejectNext() (throw), noReferenceNext()
 *   (AS returns 0, nothing is stored).
 */
final class ActionSchedulerTransport implements ITransport {

  private ?\Throwable $rejectNext = null;

  private bool $noReferenceNext = false;

  public function __construct(public readonly string $group) {}

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

    $id = as_schedule_single_action($dueAt->getTimestamp(), $c->record->integration_action, [$wrappedEnvelope], $this->group);

    return (string) (int) $id;
  }

  public function sharesConnectionWith(IOutboxStore $store): bool {
    if ($store instanceof RecordingOutboxStore) {
      $store = $store->inner;
    }
    return $store instanceof WpdbOutboxStore;
  }

  public function rejectNext(?\Throwable $e = null): void {
    $this->rejectNext = $e ?? new TransportRejected('Action Scheduler rejected the submission (injected)');
  }

  public function noReferenceNext(): void {
    $this->noReferenceNext = true;
  }
}
