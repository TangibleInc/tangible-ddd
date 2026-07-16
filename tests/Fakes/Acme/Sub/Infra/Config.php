<?php

namespace TangibleDDD\Tests\Fakes\Acme\Sub\Infra;

use TangibleDDD\Infra\IDDDConfig;

/**
 * Fake consumer whose namespace root nests INSIDE Acme's — exercises
 * longest-prefix ownership resolution.
 */
final class Config implements IDDDConfig {
  public function prefix(): string { return 'acme_sub'; }
  public function table(string $name): string { return 'wp_acme_sub_' . $name; }
  public function hook(string $name): string { return 'acme_sub_' . $name; }
  public function as_group(string $name): string { return 'acme-sub-' . $name; }
  public function option(string $name): string { return 'acme_sub_' . $name; }
  public function domain_action(string $event_name): string { return 'acme_sub_domain_' . $event_name; }
  public function integration_action(string $event_name): string { return 'acme_sub_integration_' . $event_name; }
  public function version(): string { return 'acme_sub'; }
}
