<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * Declares a listener's subscriber priority for SubscriptionRegistrar
 * ("LISTENER unless declared"). Any int; see the Subscriber phase constants.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SubscriberPriority {

  public function __construct(public readonly int $priority) {}
}
