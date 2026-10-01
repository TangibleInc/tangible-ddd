<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * Does anyone listen to this integration action? (register 3.5; wp:
 * `has_action`, OutboxProcessor.php:69). Used only for the
 * FactDeliveredUnheard diagnostic, never to skip delivery.
 *
 * Returns null when the host cannot know (the default outside WordPress).
 * Error behaviour: never throws.
 */
interface ISubscriberProbe {
  public function has_subscribers(string $integrationAction): ?bool;
}
