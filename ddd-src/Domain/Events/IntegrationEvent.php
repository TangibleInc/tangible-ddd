<?php

namespace TangibleDDD\Domain\Events;

use BackedEnum;
use DateTimeInterface;
use JsonSerializable;
use TangibleDDD\Domain\Shared\Entity;
use TangibleDDD\Domain\Shared\IJsonSerializable;
use TangibleDDD\Domain\Shared\JsonLifecycleValue;

/**
 * Base class for integration events (events published to outbox/ActionScheduler).
 *
 * Consumer plugins extend this in their generated IntegrationEvent base class,
 * which provides the prefix() method.
 */
abstract class IntegrationEvent extends DomainEvent implements IIntegrationEvent {

  public static function integration_action(): string {
    return static::prefix() . '_integration_' . static::name();
  }

  public function integration_payload(): array {
    return array_map([static::class, 'scalarise'], $this->payload());
  }

  /**
   * Convert a value to a scalar for serialization.
   */
  public static function scalarise(mixed $param): mixed {
    if (null === $param || is_scalar($param)) {
      return $param;
    }

    // Entity -> ID
    if ($param instanceof Entity) {
      return $param->get_id();
    }

    // BackedEnum -> value
    if ($param instanceof BackedEnum) {
      return $param->value;
    }

    // IJsonSerializable (our interface) -> associative array
    if ($param instanceof IJsonSerializable) {
      try {
        $result = $param->to_json(false);
        return is_object($result) ? (array) $result : $result;
      } catch (\Throwable) {
        return (string) $param;
      }
    }

    // PHP's JsonSerializable -> decoded JSON
    if ($param instanceof JsonSerializable) {
      $json = json_encode($param);
      return is_string($json) ? (json_decode($json, true) ?: (string) $param) : (string) $param;
    }

    // DateTime -> ISO 8601
    if ($param instanceof DateTimeInterface) {
      return $param->format('c');
    }

    // Array of JsonLifecycleValue -> batch serialize
    if (is_array($param) && !empty($param)) {
      $first = reset($param);
      if ($first instanceof JsonLifecycleValue) {
        return JsonLifecycleValue::array_to_json($param, false);
      }
    }

    // Array -> recursive scalarise
    if (is_array($param)) {
      $out = [];
      foreach ($param as $k => $v) {
        $out[$k] = static::scalarise($v);
      }
      return $out;
    }

    // Object -> cast to array and scalarise
    if (is_object($param)) {
      $arr = (array) $param;
      foreach ($arr as $k => $v) {
        $arr[$k] = static::scalarise($v);
      }
      return $arr;
    }

    return $param;
  }

  public function delay(): int {
    return 0;
  }

  public function is_unique(): bool {
    return false;
  }
}
