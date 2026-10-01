<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;

/**
 * The core OutboxIntegrationEventBus (port form, CONF-2) plus the fact's
 * PHP class on the sf outbox row (CR sf-1). Core's OutboxRecord does not
 * carry the class yet, so around each publish() this decorator scopes
 * get_class($event) on the DbalPostgresOutboxStore (with_event_class), and the
 * store writes it to `event_class`. Delivery needs the class to hydrate the
 * fact and match marker-interface subscriptions (D2). With any other store
 * the decorator only delegates. Once core fills OutboxRecord::$event_class
 * this class is a pass-through and can be dropped.
 */
final class FactClassRecordingEventBus implements IIntegrationEventBus {

  public function __construct(
    private readonly OutboxIntegrationEventBus $inner,
    private readonly ?DbalPostgresOutboxStore $store = null,
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
