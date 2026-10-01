<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Delivery\TransportRejected;
use TangibleDDD\Runtime\Outbox\Claim;
use TangibleDDD\Runtime\Outbox\IOutboxStore;

/**
 * The wp ITransport (register 3.5; WPC-1): Action Scheduler on the
 * WordPress connection, with the 0.6 action shape, so a 0.6 winner after a
 * rollback runs the queued deliveries unchanged:
 *
 *   hook  = the fact's integration_action (`{prefix}_integration_{name}`)
 *   args  = [the wrapped envelope], or, over Action Scheduler's 8000-byte
 *           args limit, its by-reference form (WpLargeEnvelope; D6)
 *   group = the consumer's outbox group (`{prefix}-outbox`)
 *
 * submit() schedules ONE single action at the ABSOLUTE $dueAt, also when it
 * is already past: Action Scheduler runs past-due actions at once, and the
 * one due time survives retries (bug 3; `as_enqueue_async_action` would
 * restamp it "now"). The action id is the reference; an id of 0 (AS could
 * not store it) is returned as '0', which the relay treats as a rejection
 * (CONF-4). A missing Action Scheduler throws TransportRejected.
 *
 * shares_connection(): true for the wpdb outbox store, since both write
 * through the global $wpdb; the relay then runs submit + accept in one
 * WpdbTransactionBoundary transaction, so relay failures are DB errors only.
 */
final class ActionSchedulerTransport implements ITransport {

  public function __construct(private readonly string $group) {}

  public function submit(Claim $c, array $wrappedEnvelope, \DateTimeImmutable $dueAt): ?string {
    if (!function_exists('as_schedule_single_action')) {
      throw new TransportRejected('Action Scheduler is not loaded; the fact stays in the outbox.');
    }

    $args = WpLargeEnvelope::for_transport($wrappedEnvelope, $c->record->integration_action, $c->record->event_type);
    $id = as_schedule_single_action($dueAt->getTimestamp(), $c->record->integration_action, [$args], $this->group);

    return (string) (int) $id;
  }

  public function shares_connection(IOutboxStore $store): bool {
    return $store instanceof WpdbOutboxStore;
  }
}
