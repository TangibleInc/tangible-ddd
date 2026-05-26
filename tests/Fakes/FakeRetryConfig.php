<?php

namespace TangibleDDD\Tests\Fakes;

use stdClass;
use TangibleDDD\Domain\ValueObjects\Behaviours\BaseBehaviourConfig;

class FakeRetryConfig extends BaseBehaviourConfig {
  public function __construct(public readonly int $max_retries = 3) {
    parent::__construct();
  }

  public function get_behaviour_type(): string { return 'retry'; }

  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    $d = (array) $rendered_data;
    return new static(max_retries: (int) ($d['max_retries'] ?? 3));
  }
}
