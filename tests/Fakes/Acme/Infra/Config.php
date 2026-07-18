<?php

namespace TangibleDDD\Tests\Fakes\Acme\Infra;

use TangibleDDD\Infra\IDDDConfig;

/**
 * Fake consumer config in the scaffolder's canonical location
 * (<root>\Infra\Config) — exercises namespace-root derivation.
 */
final class Config implements IDDDConfig {
  public function prefix(): string { return 'acme'; }
  public function table(string $name): string { return 'wp_acme_' . $name; }
  public function hook(string $name): string { return 'acme_' . $name; }
  public function as_group(string $name): string { return 'acme-' . $name; }
  public function option(string $name): string { return 'acme_' . $name; }
  public function domain_action(string $event_name): string { return 'acme_domain_' . $event_name; }
  public function integration_action(string $event_name): string { return 'acme_integration_' . $event_name; }
  public function version(): string { return 'acme'; }
}
