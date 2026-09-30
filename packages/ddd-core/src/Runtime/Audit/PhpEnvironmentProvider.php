<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Audit;

/** Default IEnvironmentProvider: the PHP version plus any fixed extras (e.g. plugin version). */
final class PhpEnvironmentProvider implements IEnvironmentProvider {

  /** @param array<string, scalar|null> $extra */
  public function __construct(private readonly array $extra = []) {}

  public function describe(): array {
    return ['php' => PHP_VERSION] + $this->extra;
  }
}
