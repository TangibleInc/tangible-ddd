<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

/**
 * Observes each fact at publication, after its outbox record is built
 * (register 3.9; wp: the touches indexer).
 *
 * Error behaviour: an implementation MAY throw; the caller
 * (OutboxIntegrationEventBus) catches, logs and continues. Observers never
 * break publication (A F-07).
 *
 * Connection rules: runs inside the publishing command's transaction; an
 * observer that writes must use the same connection.
 *
 * Not wired in sf or pdo by default (NullFactObserver, O4).
 */
interface IFactObserver {
  public function observe(IIntegrationEvent $e, OutboxRecord $r): void;
}
