<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Infra\IConsumerIdentity;

/** A fixed consumer identity for tests and in-memory composition. */
final class StaticConsumerIdentity implements IConsumerIdentity {

  /** @throws \InvalidArgumentException when $prefix is not [a-z0-9_]+ */
  public function __construct(
    private readonly string $prefix = 'test',
    private readonly string $version = 'dev',
  ) {
    if (!preg_match('/^[a-z0-9_]+$/', $prefix)) {
      throw new \InvalidArgumentException("Consumer prefix '$prefix' must match [a-z0-9_]+");
    }
  }

  public function prefix(): string {
    return $this->prefix;
  }

  public function version(): string {
    return $this->version;
  }
}
