<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo\Internal;

use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IDDDConfig;

/**
 * IDDDConfig over a portable IConsumerIdentity, for the core constructors
 * that still type IDDDConfig (CorrelationMiddleware, OutboxProcessor,
 * OutboxIntegrationEventBus, ProcessRunner). Only prefix() and version()
 * carry meaning; the WordPress vocabulary methods are pure string
 * derivations (no WordPress call), as in ddd-symfony's SymfonyConsumerConfig.
 * Table names follow the pdo convention `{prefix}_` + logical name.
 *
 * @internal
 */
final class IdentityConfig implements IDDDConfig {

  public function __construct(private readonly IConsumerIdentity $identity) {}

  public function prefix(): string { return $this->identity->prefix(); }
  public function version(): string { return $this->identity->version(); }
  public function table(string $name): string { return $this->prefix() . '_' . $name; }
  public function hook(string $name): string { return $this->prefix() . '_' . $name; }
  public function as_group(string $name): string { return str_replace('_', '-', $this->prefix()) . '-' . $name; }
  public function option(string $name): string { return $this->prefix() . '_' . $name; }
  public function domain_action(string $event_name): string { return $this->prefix() . '_domain_' . $event_name; }
  public function integration_action(string $event_name): string { return $this->prefix() . '_integration_' . $event_name; }
}
