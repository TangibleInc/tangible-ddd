<?php

namespace TangibleDDD\Tests\Fakes\Acme\Domain;

use TangibleDDD\Domain\Shared\JsonLifecycleValue;

/**
 * A consumer VO extending the FRAMEWORK JLV directly — no stamped
 * per-consumer JsonLifecycleValue middle class (0.2.5c: the renderer
 * resolves through owner_of()).
 */
class WidgetSpec extends JsonLifecycleValue {

  public function __construct(public readonly string $label) {}

  protected static function from_json_instance(\stdClass|array $rendered_data, ...$params): static {
    $data = (object) $rendered_data;
    return new static((string) $data->label);
  }

  public function to_json(bool $as_string = true): string|\stdClass|array {
    $data = ['label' => $this->label];
    return $as_string ? json_encode($data) : (object) $data;
  }
}
