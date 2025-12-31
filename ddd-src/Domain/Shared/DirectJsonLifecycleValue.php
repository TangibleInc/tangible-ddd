<?php

namespace TangibleDDD\Domain\Shared;

use stdClass;

/**
 * JsonLifecycleValue that can be created directly (not just from JSON).
 *
 * While JsonLifecycleValue preserves original JSON state through round-trips,
 * it requires objects to be created via from_json(). This class allows natural
 * instantiation with `new MyVO(...)` while still supporting JSON serialization.
 *
 * Use this when you need to:
 * - Create VOs programmatically (not from external JSON)
 * - Serialize VOs that were constructed directly
 * - Have VOs that work both ways (from JSON or from code)
 */
abstract class DirectJsonLifecycleValue extends JsonLifecycleValue {

  public function __construct() {
    parent::__construct();
  }

  /**
   * Serialize to JSON.
   *
   * If created via from_json(), preserves original state.
   * If created directly, serializes current properties.
   */
  public function to_json(bool $as_string = true): string|stdClass|array {
    // If we have init_state (created from JSON), use parent behavior
    if (isset($this->init_state)) {
      return parent::to_json($as_string);
    }

    // Otherwise, serialize current properties
    $std = $this->serialize_properties();

    if ($as_string) {
      return json_encode($std);
    }

    return $std;
  }

  /**
   * Serialize a single property value.
   */
  protected function serialize_one(mixed $property): mixed {
    if ($property instanceof IJsonSerializable) {
      return $property->to_json(false);
    }

    if ($property instanceof \JsonSerializable) {
      return $property->jsonSerialize();
    }

    if ($property instanceof \BackedEnum) {
      return $property->value;
    }

    return $property;
  }

  /**
   * Serialize all properties to stdClass.
   *
   * Override this if you need custom serialization logic.
   */
  protected function serialize_properties(): stdClass {
    $std = new stdClass();

    foreach (get_object_vars($this) as $name => $val) {
      // Skip internal state
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

  /**
   * Get as stdClass (for DB storage, etc.)
   */
  public function to_std(): stdClass {
    return $this->serialize_properties();
  }

  /**
   * Get as associative array.
   */
  public function to_array(): array {
    $json = $this->to_json(true);
    return json_decode($json, true);
  }

  /**
   * Create a new instance with rendered property values.
   *
   * Useful for template rendering, data transformation, etc.
   *
   * @param IValueRenderer $renderer The renderer to apply
   * @return static A new instance with rendered values
   * @throws \InvalidArgumentException If constructor params can't be resolved
   */
  public function rerender(IValueRenderer $renderer): static {
    // Get current properties (excluding internal state)
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

    $std = new stdClass();
    foreach ($constructor->getParameters() as $parameter) {
      $param_name = $parameter->getName();

      if (array_key_exists($param_name, $rendered_props_assoc)) {
        $std->$param_name = $rendered_props_assoc[$param_name];
      } elseif ($parameter->isDefaultValueAvailable()) {
        $std->$param_name = $parameter->getDefaultValue();
      } else {
        throw new \InvalidArgumentException(
          "Cannot rerender: Missing value for required parameter '{$param_name}' in " . static::class
        );
      }
    }

    return static::from_json($std);
  }
}
