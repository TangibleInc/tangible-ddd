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
    // Trajectory→Fact guard (0.2.5): a saga step announcing directly would
    // write an orphan fact (command_id null — no raiser edge). Steps
    // sequence commands; handlers announce. The ground contact (step →
    // command → handler announces) has both frames occupied and passes.
    $parent = CorrelationContext::process_frame();
    if ($parent !== null && CorrelationContext::command_frame() === null) {
      throw new FactPublishedInsideProcess(get_class($event), $parent);
    }

    // Handle is_unique: cancel existing pending events of same type
    if ($event->is_unique()) {
      $this->outbox->cancel_duplicates(
        $event::name(),
        $event->integration_payload()
      );
    }

    // Write event to outbox (within current transaction)
    $event_id = $this->outbox->write(
      $event,
      CorrelationContext::get(),
      CorrelationContext::command_id()
    );

    // Stamp the journey with the id the write path generated, so the event
    // instance in hand now carries the same identity as its outbox row.
    if (is_string($event_id) && $event_id !== '' && $event->event_id() === null) {
      $event->stamp_journey(CorrelationContext::get(), $event_id);
    }
  }
}
