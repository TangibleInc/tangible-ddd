<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Infra\IDDDConfig;

final class FakeDDDConfig implements IDDDConfig {
  public function prefix(): string { return 'test'; }
  public function table(string $name): string { return 'wp_test_' . $name; }
  public function hook(string $name): string { return 'test_' . $name; }
  public function as_group(string $name): string { return 'test-' . $name; }
  public function option(string $name): string { return 'test_' . $name; }
  public function domain_action(string $event_name): string { return 'test_domain_' . $event_name; }
  public function integration_action(string $event_name): string { return 'test_integration_' . $event_name; }
  public function version(): string { return 'test'; }
}
