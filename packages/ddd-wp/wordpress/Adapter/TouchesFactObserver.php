<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\IFactObserver;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

use function TangibleDDD\WordPress\touches_index_fact;

/**
 * The wp IFactObserver (register 1.4, 3.9): the 0.5.2 touches indexer,
 * which 0.6's OutboxIntegrationEventBus called inline. Indexes the fact's
 * declared aggregate touches into `{prefix}_touches` with the fact's id,
 * story and raiser, inside the publishing command's transaction (same wpdb
 * connection). Per consumer, built by WpHostPortFactory.
 *
 * Error behaviour: touches_index_fact() never throws by design; anything
 * that does escape is caught by the bus (observers never break publication).
 */
final class TouchesFactObserver implements IFactObserver {

  public function __construct(private readonly IDDDConfig $config) {}

  public function observe(IIntegrationEvent $e, OutboxRecord $r): void {
    touches_index_fact($this->config, $e, $r->event_id, (string) $r->correlation_id, $r->command_id);
  }
}
