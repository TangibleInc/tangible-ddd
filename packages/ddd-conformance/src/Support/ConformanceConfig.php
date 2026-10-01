<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Support;

use TangibleDDD\Infra\IDDDConfig;

/**
 * A host-neutral IDDDConfig for composing the core classes whose
 * constructors still type their consumer as IDDDConfig (CR-SP-7:
 * CorrelationMiddleware, OutboxProcessor). The table/hook/group/option
 * helpers only concatenate; nothing on the mem host reads them.
 */
final class ConformanceConfig implements IDDDConfig {

  public function __construct(
    private readonly string $prefix = 'conformance',
    private readonly string $version = 'dev',
  ) {}

  public function prefix(): string { return $this->prefix; }
  public function table(string $name): string { return $this->prefix . '_' . $name; }
  public function hook(string $name): string { return $this->prefix . '_' . $name; }
  public function as_group(string $name): string { return $this->prefix . '-' . $name; }
  public function option(string $name): string { return $this->prefix . '_' . $name; }
  public function domain_action(string $event_name): string { return $this->prefix . '_domain_' . $event_name; }
  public function integration_action(string $event_name): string { return $this->prefix . '_integration_' . $event_name; }
  public function version(): string { return $this->version; }
}
