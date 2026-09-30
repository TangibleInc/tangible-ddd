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
 * `onExhausted` (optional) is `\Closure(IIntegrationEvent $event, \Throwable $last): void`,
 * fired once by IntegrationDelivery when this subscriber reaches its budget.
 */
final class Subscriber {

  public const LISTENER = 10;
  public const IGNITION = 50;
  public const RESUME = 99;

  /**
   * @param class-string $eventClassOrMarker a concrete fact class or a marker interface (D2)
   * @param \Closure(IIntegrationEvent, string): void $handle
   * @param (\Closure(IIntegrationEvent, \Throwable): void)|null $onExhausted
   */
  public function __construct(
    public readonly string $id,
    public readonly int $priority,
    public readonly string $eventClassOrMarker,
    public readonly \Closure $handle,
    public readonly ?\Closure $onExhausted = null,
  ) {
    if ($id === '') {
      throw new \InvalidArgumentException('Subscriber id must not be empty');
    }
  }
}
