<?php

namespace TangibleDDD\Domain\Shared;

use stdClass;
use TangibleDDD\Domain\Exceptions\InvariantException;

/**
 * Base class for value objects with JSON lifecycle preservation.
 *
 * This pattern preserves the original JSON state through the object lifecycle,
 * enabling round-trip serialization without losing unknown properties.
 */
abstract class JsonLifecycleValue implements IJsonSerializable {

  protected function __construct() {}

  /**
   * The original JSON state, preserved for round-trip serialization.
   */
  protected readonly stdClass|array $init_state;

  /**
   * Optional renderer for transforming data during hydration.
   */
  private static ?IValueRenderer $renderer = null;

  /**
   * Set a global renderer for all JsonLifecycleValue subclasses.
   */
  public static function set_renderer(?IValueRenderer $renderer): void {
    self::$renderer = $renderer;
  }

  /**
   * Get the renderer for this value object.
   *
   * Override in plugin-specific subclasses to return renderer from local DI container.
   * Uses late static binding so each plugin's VOs use their own renderer.
   */
  protected static function get_renderer(): ?IValueRenderer {
    return self::$renderer;
  }

  /**
   * Create an instance from JSON data.
   */
  final public static function from_json(
    stdClass|array|string $data,
    ?IValueRenderer $renderer = null,
    ...$params
  ): static {
    if (is_string($data)) {
      $data = json_decode($data, false);
    }

    $renderer = $renderer ?? static::get_renderer();
    if ($renderer !== null) {
      $data = $renderer->render_data($data);
    }

    $instance = static::from_json_instance($data, ...$params);

    $data = static::sync_init_state($data, $instance);
    $instance->init_state = $data;

    return $instance;
  }

  /**
   * Create an instance from another IJsonSerializable.
   */
  public static function from_serializable(IJsonSerializable $serializable): static {
    return static::from_json($serializable->to_json());
  }

  /**
   * Override this method to construct your value object from rendered data.
   */
  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    return new static($rendered_data);
  }

  /**
   * Sync any properties set by from_json_instance back to init_state.
   */
  protected static function sync_init_state(stdClass|array $init_state, $instance): array|stdClass {
    $refl = new \ReflectionClass($instance);
    foreach ($refl->getProperties() as $property) {
      $name = $property->getName();
      if (in_array($name, ['init_state'])) {
        continue;
      }

      $property->setAccessible(true);
      if (!property_exists($init_state, $name)) {
        $init_state->$name = $property->getValue($instance);
      }
    }

    return $init_state;
  }

  /**
   * Serialize to JSON, preserving the original state.
   */
  public function to_json(bool $as_string = true): string|stdClass|array {
    if (!isset($this->init_state)) {
      throw new InvariantException(
        'JsonLifecycleValue objects created outside from_json() cannot be serialized. ' .
        'Use DirectJsonLifecycleValue if you need direct instantiation.'
      );
    }

    if ($as_string) {
      return json_encode($this->init_state);
    }

    return json_decode(json_encode($this->init_state), false);
  }

  /**
   * Serialize an array of JsonLifecycleValue objects.
   */
  public static function array_to_json(array $objects, bool $as_string = true): string|array {
    $mapped = array_map(
      fn($obj) => $obj->to_json(false),
      $objects
    );

    return $as_string ? json_encode($mapped) : $mapped;
  }

  /**
   * Deserialize an array of JSON objects.
   */
  public static function array_from_json(array $json, bool $preserve_keys = false, ...$from_json_args): array {
    $mapped = [];
    foreach ($json as $key => $data) {
      try {
        $instance = static::from_json($data, ...$from_json_args);
      } catch (\InvalidArgumentException) {
        continue;
      }

      if ($preserve_keys) {
        $mapped[$key] = $instance;
      } else {
        $mapped[] = $instance;
      }
    }

    return $mapped;
  }

  // ─────────────────────────────────────────────────────────────────────────
  // Polymorphic serialization (preserves class name for reconstruction)
  // ─────────────────────────────────────────────────────────────────────────

  /**
   * Serialize a JsonLifecycleValue with its class name for polymorphic reconstruction.
   *
   * Use this when you need to store a VO whose concrete type isn't known at compile time.
   */
  public static function serialize_polymorphic(self $value): array {
    return [
      '_class' => get_class($value),
      '_data' => $value->to_json(false),
    ];
  }

  /**
   * Deserialize a JsonLifecycleValue from polymorphic format.
   *
   * @param array|null $data Array with '_class' and '_data' keys
   * @return static|null The reconstructed VO, or null if invalid
   */
  public static function deserialize_polymorphic(?array $data): ?self {
    if ($data === null) {
      return null;
    }

    $class = $data['_class'] ?? null;
    $json = $data['_data'] ?? null;

    if ($class === null || $json === null) {
      return null;
    }

    if (!is_subclass_of($class, self::class)) {
      return null;
    }

    return $class::from_json($json);
  }
}
