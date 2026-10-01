<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * One subscription of a fact (register 3.5, X8 as amended by #59).
 *
 * `priority` is any int; lower runs first. The three named phases are
 * frozen constants, not an enum, because today's integration_action()
 * callers register at arbitrary priorities (e.g. 100, after resume).
 *
 * `id` must be stable across processes and releases: it keys the delivery
 * ledger per (subscriber, event_id) and the deterministic command id
 * uuid5(event_id, subscriber_id).
 *
 * `handle` is `\Closure(IIntegrationEvent $event, string $eventId): void`.
 * `on_exhausted` (optional) is `\Closure(IIntegrationEvent $event, \Throwable $last): void`,
 * fired by IntegrationDelivery when this subscriber reaches its budget and
 * re-fired on later deliveries until it returns without throwing (then the
 * ledger's terminal marker stops it). It must be idempotent.
 *
 * UNRATIFIED: `on_exhausted` is a fifth parameter beyond the register sketch;
 * see CR-2 in Runtime/API-CHANGE-REQUESTS.md.
 */
final class Subscriber {

  public const LISTENER = 10;
  public const IGNITION = 50;
  public const RESUME = 99;

  /**
   * @param class-string $event_class a concrete fact class or a marker interface (D2)
   * @param \Closure(IIntegrationEvent, string): void $handle
   * @param (\Closure(IIntegrationEvent, \Throwable): void)|null $on_exhausted
   */
  public function __construct(
    public readonly string $id,
    public readonly int $priority,
    public readonly string $event_class,
    public readonly \Closure $handle,
    public readonly ?\Closure $on_exhausted = null,
  ) {
    if ($id === '') {
      throw new \InvalidArgumentException('Subscriber id must not be empty');
    }
  }
}
