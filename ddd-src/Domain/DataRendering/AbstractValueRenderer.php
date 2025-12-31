<?php

namespace TangibleDDD\Domain\DataRendering;

use stdClass;
use TangibleDDD\Domain\Shared\IValueRenderer;

/**
 * Base implementation that walks data structures and renders string values.
 */
abstract class AbstractValueRenderer implements IValueRenderer {

  public function render_data(array|object|null $data): stdClass|array {
    $rendered_data = is_array($data) ? [] : new stdClass();

    if (null === $data) {
      return $rendered_data;
    }

    if (is_array($data)) {
      foreach ($data as $key => $value) {
        if (is_string($value)) {
          $rendered_data[$key] = $this->render_value($value);
        } elseif (is_array($value) || is_object($value)) {
          $rendered_data[$key] = $this->render_data($value);
        } else {
          $rendered_data[$key] = $value;
        }
      }
    } elseif (is_object($data)) {
      foreach (get_object_vars($data) as $property => $value) {
        if (is_string($value)) {
          $rendered_data->$property = $this->render_value($value);
        } elseif ($value instanceof \BackedEnum) {
          $rendered_data->$property = $value->value;
        } elseif (is_array($value) || is_object($value)) {
          $rendered_data->$property = $this->render_data($value);
        } else {
          $rendered_data->$property = $value;
        }
      }
    }

    return $rendered_data;
  }

  /**
   * Render a single string value (template substitution, etc.)
   */
  abstract protected function render_value(string $value): string;
}
