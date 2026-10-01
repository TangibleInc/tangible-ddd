<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;

/** A step checkpoint for the D3 processes: a text and a list. */
final class StepNote extends DirectJsonLifecycleValue {

  /** @param list<string> $items */
  public function __construct(public string $text = '', public array $items = []) {
    parent::__construct();
  }

  protected static function from_json_instance(\stdClass|array $data, ...$params): static {
    $data = (array) $data;
    return new static((string) ($data['text'] ?? ''), array_values(array_map('strval', (array) ($data['items'] ?? []))));
  }
}
