<?php

namespace TangibleDDD\Tests\Fakes;

use stdClass;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;

class FakeStopConfig extends BaseBehaviourConfig {
  public function get_behaviour_type(): string { return 'stop'; }

  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    return new static();
  }
}
