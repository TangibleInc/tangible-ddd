<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Infra\IConsumerIdentity;

/**
 * A consumer known only by its prefix (CR-SP-2): what operator commands
 * carry (`consumer_prefix` on the four repair commands), so their handlers
 * can ask HostDefaults::for() for that consumer's ports even when the
 * consumer is not registered in this request (a deactivated plugin's
 * leftover rows, a "ghost" on the dashboard). Version is 'unknown'.
 *
 * Error behaviour: \InvalidArgumentException for a prefix outside the
 * frozen alphabet [a-z0-9_]+ (it is interpolated into table names).
 */
final class ConsumerPrefix implements IConsumerIdentity {

  public function __construct(private readonly string $prefix) {
    if (!preg_match('/^[a-z0-9_]+$/', $prefix)) {
      throw new \InvalidArgumentException("Invalid consumer prefix: {$prefix}");
    }
  }

  public function prefix(): string {
    return $this->prefix;
  }

  public function version(): string {
    return 'unknown';
  }
}
