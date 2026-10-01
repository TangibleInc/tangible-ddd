<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;

/**
 * The core OutboxIntegrationEventBus (port form) plus the fact's PHP class
 * on the pdo outbox row (wave3-pdo-compose CR-PC-2; the pdo twin of
 * ddd-symfony's decorator for CR sf-1). Core's OutboxRecord carries no
 * class, so around each publish() this scopes get_class($event) on the
 * PdoOutboxStore, which writes it to `event_class`; the relay copies it to
 * the deliver job, and PdoDeliveryWorker hydrates the fact with it (and
 * matches marker-interface subscriptions, D2).
 *
 * With no store it only delegates. Once core fills the class on
 * OutboxRecord this class is a pass-through and can be dropped.
 */
final class FactClassRecordingEventBus implements IIntegrationEventBus {

  public function __construct(
    private readonly OutboxIntegrationEventBus $inner,
    private readonly ?PdoOutboxStore $store = null,
  ) {}

  public function publish(IIntegrationEvent $event): void {
    if ($this->store === null) {
      $this->inner->publish($event);
      return;
    }
    $this->store->with_event_class(get_class($event), fn () => $this->inner->publish($event));
  }

  public function inner(): OutboxIntegrationEventBus {
    return $this->inner;
  }
}
