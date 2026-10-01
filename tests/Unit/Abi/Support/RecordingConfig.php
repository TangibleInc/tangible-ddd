<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Abi\Support;

use TangibleDDD\Infra\IDDDConfig;

/** An IDDDConfig that delegates and records every derived name the framework asks it for. */
final class RecordingConfig implements IDDDConfig {

  /** @var array<string, list<string>> method => derived names, in call order */
  public array $derived = [];

  public function __construct(private readonly IDDDConfig $inner) {}

  public function prefix(): string {
    return $this->inner->prefix();
  }

  public function table(string $name): string {
    return $this->derived['table'][] = $this->inner->table($name);
  }

  public function hook(string $name): string {
    return $this->derived['hook'][] = $this->inner->hook($name);
  }

  public function as_group(string $name): string {
    return $this->derived['as_group'][] = $this->inner->as_group($name);
  }

  public function option(string $name): string {
    return $this->derived['option'][] = $this->inner->option($name);
  }

  public function domain_action(string $event_name): string {
    return $this->derived['domain_action'][] = $this->inner->domain_action($event_name);
  }

  public function integration_action(string $event_name): string {
    return $this->derived['integration_action'][] = $this->inner->integration_action($event_name);
  }

  public function version(): string {
    return $this->inner->version();
  }

  /** @return list<string> */
  public function take(string $method): array {
    $names = $this->derived[$method] ?? [];
    $this->derived[$method] = [];
    return $names;
  }
}
