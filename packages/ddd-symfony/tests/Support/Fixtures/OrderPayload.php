<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use stdClass;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;

final class OrderPayload extends DirectJsonLifecycleValue {

  public function __construct(
    public readonly string $note = '',
    public readonly int $count = 0,
  ) {
    parent::__construct();
  }

  protected static function from_json_instance(stdClass|array $data, ...$params): static {
    $d = (array) $data;
    return new static(note: (string) ($d['note'] ?? ''), count: (int) ($d['count'] ?? 0));
  }
}
