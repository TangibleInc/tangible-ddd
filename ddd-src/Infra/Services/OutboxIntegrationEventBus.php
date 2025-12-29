<?php

namespace TangibleDDD\Infra\Services;

use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\IOutboxRepository;

/**
 * Outbox-backed integration event bus.
 *
 * Instead of publishing directly to ActionScheduler, this bus writes events
 * to the transactional outbox table. A separate processor drains the outbox
 * to ActionScheduler.
 *
 * This ensures events are never lost due to crashes between transaction commit
 * and ActionScheduler enqueue.
 */
final class OutboxIntegrationEventBus implements IIntegrationEventBus {

  public function __construct(
    private readonly IOutboxRepository $outbox
  ) {}

  public function publish(IIntegrationEvent $event): void {
    // Handle is_unique: cancel existing pending events of same type
    if ($event->is_unique()) {
      $this->outbox->cancel_duplicates(
        $event::name(),
        $event->integration_payload()
      );
    }

    // Write event to outbox (within current transaction)
    $this->outbox->write(
      $event,
      CorrelationContext::get(),
      CorrelationContext::command_id()
    );
  }
}
