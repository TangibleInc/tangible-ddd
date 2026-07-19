<?php

namespace TangibleDDD\Infra\Services;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
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
    $cause = Correlation::peek()?->cause;

    // Trajectory→Fact guard: a saga step announcing directly would write an
    // orphan fact (no raiser edge). Steps sequence commands; handlers
    // announce. Read off the ambient cause: inside a wake the cause is the
    // trajectory; the ground contact (step → command → handler announces)
    // runs inside an ACT scope and passes.
    if ($cause?->kind === Kind::Trajectory) {
      throw new FactPublishedInsideProcess(get_class($event), $cause->id);
    }

    // Handle is_unique: cancel existing pending events of same type
    if ($event->is_unique()) {
      $this->outbox->cancel_duplicates(
        $event::name(),
        $event->integration_payload()
      );
    }

    // The story — a fact announced from a flat context (wp ddd announce)
    // starts its own, minted without touching the ambient — and the raiser
    // edge: a fact's parent is the ACT it was announced from, null for the
    // sanctioned command-less doors.
    $correlation = Correlation::peek()?->correlation_id ?? Uuid::v4();
    $raiser = $cause?->kind === Kind::Act ? $cause->id : null;

    $event_id = $this->outbox->write($event, $correlation, $raiser);

    // Mark the instance as published (0.3): facts carry no identity slots —
    // the at-rest identity is the outbox row, the in-flight identity is the
    // envelope. PublishedFacts is the re-raise guard's memory.
    if (is_string($event_id) && $event_id !== '') {
      \TangibleDDD\Application\Events\PublishedFacts::mark($event, $event_id);
    }
  }
}
