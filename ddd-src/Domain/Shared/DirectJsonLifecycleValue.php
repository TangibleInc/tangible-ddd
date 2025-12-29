<?php

namespace TangibleDDD\Domain\Shared;

use stdClass;

/**
 * JsonLifecycleValue that can also be created directly (not just from JSON).
 */
abstract class DirectJsonLifecycleValue extends JsonLifecycleValue {

  public function __construct() {
    parent::__construct();
  }

  /**
   * Serialize to JSON, using reflection if no init_state.
   */
  public function to_json(bool $as_string = true): array|string|stdClass {
    if (!empty($this->init_state)) {
      return parent::to_json($as_string);
    }

    $std = $this->serialize_properties();

    if ($as_string) {
      return json_encode($std);
    }

    return $std;
  }

  private function serialize_one(mixed $property): mixed {
    if ($property instanceof JsonLifecycleValue) {
      return $property->to_json(false);
    }
    return $property;
  }

  protected function serialize_properties(): stdClass {
    $std = new stdClass();

    foreach (get_object_vars($this) as $name => $val) {
      if ($name === 'init_state') {
        continue;
      }

      if (is_array($val)) {
        $std->$name = array_map(fn($item) => $this->serialize_one($item), $val);
      } else {
        $std->$name = $this->serialize_one($val);
      }
    }

    return $std;
  }

  public function to_std(): stdClass {
    return $this->to_json(false);
  }

  public function to_array(): array {
    return (array) json_decode(json_encode($this->to_json(false)), true);
  }

  public function rerender(IValueRenderer $renderer): static {
    $current_props = get_object_vars($this);
    unset($current_props['init_state']);

    $rendered_props = $renderer->render_data($current_props);
    $rendered_props_assoc = (array) $rendered_props;

    $reflectionClass = new \ReflectionClass(static::class);
    $constructor = $reflectionClass->getConstructor();
    if (!$constructor) {
      throw new \InvalidArgumentException(
        "Cannot rerender: Class " . static::class . " has no constructor."
      );
    }

    $parameters = $constructor->getParameters();
    $std = new stdClass();

    foreach ($parameters as $parameter) {
      $param_name = $parameter->getName();

      if (array_key_exists($param_name, $rendered_props_assoc)) {
        $param_value = $rendered_props_assoc[$param_name];
      } elseif ($parameter->isDefaultValueAvailable()) {
        $param_value = $parameter->getDefaultValue();
      } else {
        throw new \InvalidArgumentException(
          "Cannot rerender: Missing value for required constructor parameter '{$param_name}' in class " . static::class
        );
      }

      $std->$param_name = $param_value;
    }

    return static::from_json_instance($std);
  }
}
