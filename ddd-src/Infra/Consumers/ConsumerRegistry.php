<?php

namespace TangibleDDD\Infra\Consumers;

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

  public static function add(IDDDConfig $config, callable $di_getter, ?string $label = null, ?string $namespace_root = null): ConsumerHandle {
    $handle = new ConsumerHandle($config, $di_getter, $label, $namespace_root);
    self::$consumers[$config->prefix()] = $handle;

    return $handle;
  }

  /**
   * The consumer whose namespace root contains $class — longest match wins,
   * whole segments only (root `Foo\Bar` owns `Foo\Bar\X` but not
   * `Foo\Barbecue\X`). This is the resolution that lets a shared framework
   * base class (Command, DomainEvent, …) answer prefix()/container() for
   * whichever consumer declared the concrete subclass, via static::class.
   *
   * @throws NoConsumerOwnsClass when no registered root contains $class.
   */
  public static function owner_of(string $class): ConsumerHandle {
    $best = null;
    $best_length = -1;

    foreach (self::$consumers as $handle) {
      $root = $handle->namespace_root();
      if ($root === '') {
        continue;
      }

      $owns = $class === $root || str_starts_with($class, $root . '\\');
      if ($owns && strlen($root) > $best_length) {
        $best = $handle;
        $best_length = strlen($root);
      }
    }

    if ($best === null) {
      throw NoConsumerOwnsClass::for_class(
        $class,
        array_map(static fn (ConsumerHandle $h) => $h->namespace_root(), array_values(self::$consumers)),
      );
    }

    return $best;
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
