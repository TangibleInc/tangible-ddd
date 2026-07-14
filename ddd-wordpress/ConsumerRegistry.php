<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\IDDDConfig;

/**
 * The framework's memory of its consumers.
 *
 * Populated implicitly: register_hooks() (and therefore boot()) announces
 * the calling plugin, so every correctly-wired consumer appears here with
 * zero extra code — and a plugin missing from the registry is a plugin
 * whose framework wiring is broken. Keyed by prefix; re-registration
 * replaces (idempotent across boot() + register_hooks() both announcing).
 *
 * Read through consumers() (ddd-wordpress/hooks.php), which applies the
 * `tangible_ddd_consumers` filter — relabel, hide, or inject there, not here.
 */
final class ConsumerRegistry {

  /** @var array<string, ConsumerHandle> prefix => handle */
  private static array $consumers = [];

  public static function add(IDDDConfig $config, callable $di_getter, ?string $label = null): ConsumerHandle {
    $handle = new ConsumerHandle($config, $di_getter, $label);
    self::$consumers[$config->prefix()] = $handle;

    return $handle;
  }

  /** @return array<string, ConsumerHandle> unfiltered; prefer consumers() */
  public static function all(): array {
    return self::$consumers;
  }

  /** Test seam. */
  public static function reset(): void {
    self::$consumers = [];
  }
}
