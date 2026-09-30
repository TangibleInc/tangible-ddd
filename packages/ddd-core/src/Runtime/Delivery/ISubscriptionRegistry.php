<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * Who subscribes to which fact (register 3.5).
 *
 * - add(): boot time only. A second Subscriber with an id already present
 *   is ignored (first wins), so a double boot does not double-deliver.
 * - for(): subscribers whose eventClassOrMarker the fact class is_a (so
 *   marker interfaces work, D2), ordered by priority ascending, then
 *   registration order.
 *
 * Error behaviour: never throws for well-formed input.
 * Lifetime: process-static after boot; not reset between messages.
 * Implementations: SubscriptionRegistry (in-process; pdo, mem), sf's
 * compile-time map, wp's hook-backed transitional registry (wave 2).
 */
interface ISubscriptionRegistry {

  public function add(Subscriber $s): void;

  /** @return list<Subscriber> ordered by priority ascending, then registration */
  public function for(string $eventClass): array;
}
