<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Infrastructure\FactDeliveredUnheard;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;

/**
 * AW3: a DddSignal listener that keeps FactDeliveredUnheard for the
 * operator view. The relay signals a fact no consumer subscribes to; this
 * notes it on the raising consumer's outbox row (`unheard_at`), which
 * DbalUnheardFactSource lists in layer `relay`.
 *
 * $stores holds each consumer's DbalPostgresOutboxStore by consumer prefix.
 * Best effort: a note that cannot be written is logged, never thrown (signal
 * listeners must not break the relay).
 */
final class UnheardFactNotes {

  public function __construct(
    private readonly ContainerInterface $stores,
    private readonly ?LoggerInterface $logger = null,
  ) {}

  public function __invoke(DddSignal $signal): void {
    if (!$signal->event instanceof FactDeliveredUnheard) {
      return;
    }
    $prefix = $signal->consumer->prefix();
    if (!$this->stores->has($prefix)) {
      return;
    }
    $eventId = $signal->event->entry()->event_id;
    try {
      /** @var DbalPostgresOutboxStore $store */
      $store = $this->stores->get($prefix);
      $store->note_unheard($eventId);
    } catch (\Throwable $e) {
      $this->logger?->warning("[ddd relay] could not note unheard fact $eventId for the operator view: {$e->getMessage()}", ['exception' => $e]);
    }
  }
}
