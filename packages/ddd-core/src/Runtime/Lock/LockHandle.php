<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Lock;

/**
 * Proof of one successful acquisition. Adapters put whatever they need to
 * release into $token (lock name, connection id, reentrancy ticket).
 */
final class LockHandle {

  public function __construct(
    public readonly LockKey $key,
    public readonly string $token,
  ) {}
}
