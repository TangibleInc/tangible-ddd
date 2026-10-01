<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Infra\IDDDConfig;

/**
 * A plain IDDDConfig for core tests. The 0.6 constructors keep typing their
 * config as IDDDConfig (autowiring in consumers' runtime-compiled containers
 * resolves that alias, not IConsumerIdentity), so core composes them with
 * this host-neutral value.
 */
final class AcmeConfig implements IDDDConfig {

  public function __construct(private readonly string $prefix = 'acme', private readonly string $version = '1.0.0') {}

  public function prefix(): string { return $this->prefix; }
  public function table(string $name): string { return $this->prefix . '_' . $name; }
  public function hook(string $name): string { return $this->prefix . '_' . $name; }
  public function as_group(string $name): string { return $this->prefix . '-' . $name; }
  public function option(string $name): string { return $this->prefix . '_' . $name; }
  public function domain_action(string $event_name): string { return $this->prefix . '_domain_' . $event_name; }
  public function integration_action(string $event_name): string { return $this->prefix . '_integration_' . $event_name; }
  public function version(): string { return $this->version; }
}
