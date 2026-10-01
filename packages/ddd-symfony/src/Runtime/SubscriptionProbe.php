<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use TangibleDDD\Runtime\Delivery\ISubscriberProbe;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;

/**
 * The sf ISubscriberProbe (AW3): does any consumer of this app subscribe to
 * the fact an integration action names? The core relay step asks it for
 * every claim and raises FactDeliveredUnheard on a false answer (the fact is
 * still delivered).
 *
 * The action is mapped to its fact class by $class_of (the claimed outbox
 * row's class, then the compile-time action map); a class it cannot map is
 * "unknown" (null, no signal). A CompiledSubscriptionRegistry is asked
 * without building a subscriber (no listener is constructed by the relay);
 * any other registry through for().
 *
 * Error behaviour: never throws (an error is "unknown").
 */
final class SubscriptionProbe implements ISubscriberProbe {

  /**
   * @param list<ISubscriptionRegistry> $registries every consumer's registry
   * @param \Closure(string): ?string $class_of integration action → fact class
   */
  public function __construct(
    private readonly array $registries,
    private readonly \Closure $class_of,
  ) {}

  public function has_subscribers(string $integrationAction): ?bool {
    try {
      $class = ($this->class_of)($integrationAction);
      if ($class === null || !class_exists($class)) {
        return null;
      }
      foreach ($this->registries as $registry) {
        $heard = $registry instanceof CompiledSubscriptionRegistry ? $registry->has_subscribers($class) : $registry->for($class) !== [];
        if ($heard) {
          return true;
        }
      }
      return false;
    } catch (\Throwable) {
      return null;
    }
  }
}
