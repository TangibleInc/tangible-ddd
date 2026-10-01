<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Events;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/** D10: a cron tick (TXP's CronEntryDue); `due_at` is the tick time, ISO 8601 UTC. */
final class CronTicked extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(public readonly string $entry, public readonly string $due_at) {}

  public function payload(): array {
    return $this->integration_payload();
  }
}
