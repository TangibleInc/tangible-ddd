<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Transitional;

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
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;

/**
 * TRANSITIONAL stand-in for the core form of OutboxIntegrationEventBus
 * (CONF-2: over IOutboxStore + IClock, absolute UTC due_at set once, 0.6
 * stamps kept), which wave-2 core ships with the move; the 0.6 bus writes
 * through the WordPress IOutboxRepository and touches indexer. Wired as
 * `tangible_ddd.integration_bus`; round 3 points that id at the core class.
 *
 * Same stamps as 0.6: the Trajectory→Fact guard, the ambient story or a
 * fresh one, the raiser edge only from an Act, the next story position,
 * then PublishedFacts::mark. due_at = now + delay(), ABSOLUTE, UTC (bug 3).
 * On the sf store it also records the fact's PHP class (CR sf-1).
 */
final class PortOutboxEventBus implements IIntegrationEventBus {

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

    $record = new OutboxRecord(
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
    );

    if ($this->outbox instanceof DbalPostgresOutboxStore) {
      $this->outbox->appendFact($record, get_class($event));
    } else {
      $this->outbox->append($record);
    }

    PublishedFacts::mark($event, $eventId);
  }
}
