<?php

namespace TangibleDDD\Tests\Fakes;

use stdClass;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;
use TangibleDDD\Domain\ValueObjects\Behaviours\ISagaBehaviour;

class FakeSagaConfig extends BaseBehaviourConfig implements ISagaBehaviour {
  public function __construct(private readonly int $phases = 2) {
    parent::__construct();
  }

  public function no_phases(): int { return $this->phases; }
  public function get_behaviour_type(): string { return 'saga'; }

  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    $d = (array) $rendered_data;
    return new static(phases: (int) ($d['phases'] ?? 2));
  }
}
