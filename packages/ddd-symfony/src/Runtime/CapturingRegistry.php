<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;

/**
 * Collects what SubscriptionRegistrar adds, so CompiledSubscriptionRegistry
 * can build a Subscriber through the core registrar on demand.
 *
 * @internal
 */
final class CapturingRegistry implements ISubscriptionRegistry {

  /** @var list<Subscriber> */
  public array $subscribers = [];

  public function add(Subscriber $s): void {
    $this->subscribers[] = $s;
  }

  public function for(string $eventClass): array {
    return array_values(array_filter($this->subscribers, static fn (Subscriber $s) => is_a($eventClass, $s->eventClassOrMarker, true)));
  }
}
