<?php

namespace TangibleDDD\Tests\Fakes;

use stdClass;
use TangibleDDD\Domain\ValueObjects\Behaviours\BatchableBehaviourConfig;

class FakeBatchableConfig extends BatchableBehaviourConfig {
  public function __construct(array $batch = [], private readonly int $batch_size = 5) {
    parent::__construct($batch);
  }

  public function get_behaviour_type(): string { return 'batch'; }
  public function get_default_batch_size(): int { return $this->batch_size; }
  public function clone_with_batch(array $batch): static { return new static($batch, $this->batch_size); }

  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    $d = (array) $rendered_data;
    return new static(batch: $d['batch'] ?? [], batch_size: $d['batch_size'] ?? 5);
  }
}
