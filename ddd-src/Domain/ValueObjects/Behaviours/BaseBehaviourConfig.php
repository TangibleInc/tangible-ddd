<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

use stdClass;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;

/**
 * Base class for workflow behaviour configs.
 *
 * IMPORTANT: Tangible-DDD does not hardcode any behaviour types.
 * Consumer plugins should register behaviour type -> config class mapping at runtime using:
 *
 *   BaseBehaviourConfig::register_type('retry', MyRetryBehaviourConfig::class);
 *
 * This keeps the framework generic while preserving Cred's polymorphic JSON deserialization pattern.
 */
abstract class BaseBehaviourConfig extends DirectJsonLifecycleValue {

  /** @var array<string, class-string<BaseBehaviourConfig>> */
  private static array $type_map = [];

  /**
   * Register a behaviour config class for a given type.
   *
   * @param string $type
   * @param class-string<BaseBehaviourConfig> $class
   */
  public static function register_type(string $type, string $class): void {
    self::$type_map[$type] = $class;
  }

  /**
   * Resolve a behaviour config class for a type.
   *
   * @return class-string<BaseBehaviourConfig>
   */
  public static function class_for_type(string $type): string {
    $class = self::$type_map[$type] ?? null;
    if (!$class) {
      throw new \InvalidArgumentException("Invalid behaviour type: {$type}");
    }
    return $class;
  }

  /**
   * A stable behaviour type identifier (e.g. "retry", "stop").
   * Must coincide with the persisted "type" value.
   */
  abstract public function get_behaviour_type(): string;

  protected static function from_json_instance(stdClass|array $rendered_data, ...$params): static {
    $data = is_array($rendered_data) ? (object) $rendered_data : $rendered_data;
    $type = (string) ($data->type ?? '');

    $class = static::class_for_type($type);

    // IMPORTANT: call subclass from_json_instance so we don't set init_state twice.
    /** @var static $instance */
    $instance = $class::from_json_instance($data, ...$params);
    return $instance;
  }

  protected function serialize_properties(): stdClass {
    $std = parent::serialize_properties();
    $std->type = $this->get_behaviour_type();
    return $std;
  }
}


