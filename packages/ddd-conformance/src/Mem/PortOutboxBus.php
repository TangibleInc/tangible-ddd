<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Application\Events\PublishedFacts;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Infra\Services\FactPublishedInsideProcess;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

/**
 * WAVE-1 STAND-IN for the core form of OutboxIntegrationEventBus (register
 * 1.4 split), which lands with the wave-2 move. The 0.6 bus writes through
 * the WordPress IOutboxRepository and the touches indexer, so the mem host
 * cannot use it yet (api change request CONF-2).
 *
 * Same stamps as 0.6 (OutboxIntegrationEventBus::publish,
 * OutboxRepository::write): the Trajectory→Fact guard, the ambient story or
 * a fresh one, the raiser edge only from an Act, the next story position,
 * then PublishedFacts::mark. The port difference is `due_at`: ABSOLUTE,
 * UTC, computed once here from IClock + delay() (bug 3).
 */
final class PortOutboxBus implements IIntegrationEventBus {

  public function __construct(
    private readonly IOutboxStore $outbox,
    private readonly IClock $clock,
    private readonly OutboxConfig $config = new OutboxConfig(),
  ) {}

  public function publish(IIntegrationEvent $event): void {
    $ambient = Correlation::peek();
    $cause = $ambient?->cause;

    if ($cause?->kind === Kind::Trajectory) {
      throw new FactPublishedInsideProcess(get_class($event), $cause->id);
    }

    $eventId = Uuid::v4();
    $delay = max(0, $event->delay());
    $payload = $event->integration_payload();

    $this->outbox->append(new OutboxRecord(
      event_id: $eventId,
      event_type: $event::name(),
      integration_action: $event::integration_action(),
      correlation_id: $ambient?->correlation_id ?? Uuid::v4(),
      sequence: $ambient !== null ? Correlation::next_sequence() : 1,
      command_id: $cause?->kind === Kind::Act ? $cause->id : null,
      payload: $payload,
      due_at: $this->clock->now()->modify("+{$delay} seconds"),
      is_unique: $event->is_unique(),
      payload_signature: $event->is_unique() ? $payload : null,
      max_attempts: $this->config->max_attempts,
    ));

    PublishedFacts::mark($event, $eventId);
  }
}
