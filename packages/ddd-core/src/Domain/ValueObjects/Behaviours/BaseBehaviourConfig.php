<?php

namespace TangibleDDD\Domain\ValueObjects\Behaviours;

use stdClass;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;
use TangibleDDD\Runtime\HostDefaults;

/**
 * Base class for workflow behaviour configs.
 *
 * IMPORTANT: Tangible-DDD does not hardcode any behaviour types.
 * Consumer plugins should register behaviour type -> config class mapping at runtime using:
 *
 *   BaseBehaviourConfig::register_type('retry', MyRetryBehaviourConfig::class);
 *
 * This keeps the framework generic while preserving Cred's polymorphic JSON deserialization pattern.
 *
 * Wave 5 (TXP demand W2): the map lives behind the IBehaviourTypes port the
 * host provides through HostDefaults. register_type() and class_for_type()
 * stay as a facade for 0.6 callers: register_type() writes to the host's
 * registry, or, before the host provided one, to a process-wide fallback;
 * class_for_type() asks the host's registry first, then the fallback, so a
 * type registered at include time stays resolvable after the host boots.
 */
abstract class BaseBehaviourConfig extends DirectJsonLifecycleValue {

  /** Registrations made before (or without) a host registry. */
  private static ?BehaviourTypes $early = null;

  /**
   * Register a behaviour config class for a given type.
   *
   * @param string $type
   * @param class-string<BaseBehaviourConfig> $class
   */
  public static function register_type(string $type, string $class): void {
    (self::host_types() ?? self::$early ??= new BehaviourTypes())->register($type, $class);
  }

  /**
   * Resolve a behaviour config class for a type.
   *
   * @return class-string<BaseBehaviourConfig>
   * @throws \InvalidArgumentException for a type no registry knows
   */
  public static function class_for_type(string $type): string {
    $class = self::host_types()?->find($type) ?? self::$early?->find($type);
    if (!$class) {
      throw new \InvalidArgumentException("Invalid behaviour type: {$type}");
    }
    return $class;
  }

  private static function host_types(): ?IBehaviourTypes {
    $types = HostDefaults::get(IBehaviourTypes::class);
    return $types instanceof IBehaviourTypes ? $types : null;
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


