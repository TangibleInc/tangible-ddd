<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\Delivery\ISubscriberProbe;

/**
 * The wp ISubscriberProbe (register 1.4, OutboxProcessor.php:69 in 0.6):
 * has_action() on the integration hook, read at drain time. Only feeds the
 * FactDeliveredUnheard diagnostic; never skips delivery. Null when no hook
 * system is loaded. Never throws.
 */
final class HasActionSubscriberProbe implements ISubscriberProbe {

  public function hasSubscribers(string $integrationAction): ?bool {
    return function_exists('has_action') ? (bool) has_action($integrationAction) : null;
  }
}
