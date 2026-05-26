<?php

namespace TangibleDDD\Tests\Fakes;

use stdClass;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;

class FakePayload extends DirectJsonLifecycleValue {
  public function __construct(
    public readonly string $data = '',
    public readonly int $counter = 0
  ) {
    parent::__construct();
  }

  protected static function from_json_instance(stdClass|array $data, ...$params): static {
    $d = (array) $data;
    return new static(data: $d['data'] ?? '', counter: $d['counter'] ?? 0);
  }
}
