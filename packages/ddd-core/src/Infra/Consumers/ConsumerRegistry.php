<?php

namespace TangibleDDD\Infra\Consumers;

use TangibleDDD\Infra\IDDDConfig;

/**
 * The framework's memory of its consumers.
 *
 * Top-level consumers own persistence, hooks, workers, CLI, and dashboard
 * identity. Module handles are namespace-routing overlays only and never
 * appear in all().
 *
 * Populated implicitly: register_hooks() (and therefore boot()) announces
 * the calling plugin. Top-level registration is replaceable until a module
 * attaches; after that, an exact repeat is idempotent and a conflicting
 * replacement fails so module config and bridged host services cannot split.
 *
 * Read through consumers() (ddd-wordpress/hooks.php), which applies the
 * `tangible_ddd_consumers` filter — relabel, hide, or inject there, not here.
 */
final class ConsumerRegistry {

  /** @var array<string, ConsumerHandle> prefix => handle */
  private static array $consumers = [];

  /** @var array<string, array{host_prefix: string, handle: ConsumerHandle}> namespace root => module route */
  private static array $modules = [];

  public static function add(IDDDConfig $config, callable $di_getter, ?string $label = null, ?string $namespace_root = null): ConsumerHandle {
    $existing = self::$consumers[$config->prefix()] ?? null;
    if ($existing !== null && self::has_modules_for($config->prefix())) {
      if (!$existing->matches_registration($config, $di_getter, $label, $namespace_root)) {
        throw new \LogicException(
          "DDD consumer \"{$config->prefix()}\" cannot be replaced after modules are registered",
        );
      }

      return $existing;
    }

    $handle = new ConsumerHandle($config, $di_getter, $label, $namespace_root);
    self::assert_preserves_module_ownership($handle);
    self::$consumers[$config->prefix()] = $handle;

    return $handle;
  }

  /** Resolve one top-level persistence consumer by prefix. */
  public static function consumer(string $prefix): ConsumerHandle {
    if (!isset(self::$consumers[$prefix])) {
      throw new \InvalidArgumentException("No registered DDD consumer has prefix \"$prefix\"");
    }

    return self::$consumers[$prefix];
  }

  /** Runtime factory seam for module containers that share their host identity. */
  public static function config_for(string $prefix): IDDDConfig {
    return self::consumer($prefix)->config();
  }

  /**
   * Resolve an actual public service from a host's runtime container.
   *
   * This is intentionally a get-only bridge for independently dumped module
   * containers. It exposes no definitions or mutation API.
   */
  public static function service_for(string $prefix, string $service_id): mixed {
    $container = self::consumer($prefix)->container();
    if (!method_exists($container, 'get')) {
      throw new \UnexpectedValueException(
        "DDD consumer \"$prefix\" container cannot resolve services"
      );
    }

    return $container->get($service_id);
  }

  /**
   * Add or replace a pre-runtime module route below an existing host.
   *
   * The returned handle shares the host's exact config object and label, but
   * resolves the module container. It participates in owner_of() only: all()
   * remains the top-level persistence/dashboard consumer list. WordPress
   * callers must use boot_module(), whose lifecycle guard prevents a direct
   * route replacement after listeners and process hooks have been installed.
   */
  public static function add_module(
    string $host_prefix,
    string $namespace_root,
    callable $di_getter,
  ): ConsumerHandle {
    $host = self::consumer($host_prefix);
    $root = trim($namespace_root, '\\');
    $host_root = trim($host->namespace_root(), '\\');

    if ($root === '' || $host_root === '' || !str_starts_with($root, $host_root . '\\')) {
      throw new \InvalidArgumentException(
        "Module namespace \"$namespace_root\" must be a strict descendant of host \"$host_root\""
      );
    }

    $nearest = self::best_owner($root, array_values(self::$consumers));
    if ($nearest === null || $nearest->prefix() !== $host_prefix) {
      $owner = $nearest?->prefix() ?? '(none)';
      throw new \InvalidArgumentException(
        "Module namespace \"$root\" belongs to the more specific consumer \"$owner\""
      );
    }

    $existing = self::$modules[$root] ?? null;
    if ($existing !== null && $existing['host_prefix'] !== $host_prefix) {
      throw new \InvalidArgumentException(
        "Module namespace \"$root\" is already registered for consumer \"{$existing['host_prefix']}\""
      );
    }

    $handle = new ConsumerHandle(
      $host->config(),
      $di_getter,
      $host->label(),
      $root,
    );
    self::$modules[$root] = [
      'host_prefix' => $host_prefix,
      'handle' => $handle,
    ];

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
    $routes = array_values(self::$consumers);
    foreach (self::$modules as $module) {
      $routes[] = $module['handle'];
    }

    $best = self::best_owner($class, $routes);

    if ($best === null) {
      throw NoConsumerOwnsClass::for_class(
        $class,
        array_map(static fn (ConsumerHandle $h) => $h->namespace_root(), $routes),
      );
    }

    return $best;
  }

  /**
   * Enumerate routing modules attached to one top-level host, in registration
   * order. Modules remain absent from all().
   *
   * @return array<string, ConsumerHandle> namespace root => module route
   */
  public static function modules_for(string $host_prefix): array {
    self::consumer($host_prefix);

    $modules = [];
    foreach (self::$modules as $root => $module) {
      if ($module['host_prefix'] === $host_prefix) {
        $modules[$root] = $module['handle'];
      }
    }

    return $modules;
  }

  /** @return array<string, ConsumerHandle> top-level consumers only; prefer consumers() */
  public static function all(): array {
    return self::$consumers;
  }

  /** Test seam. */
  public static function reset(): void {
    self::$consumers = [];
    self::$modules = [];
  }

  /**
   * @param list<ConsumerHandle> $handles
   */
  private static function best_owner(string $class, array $handles): ?ConsumerHandle {
    $best = null;
    $best_length = -1;

    foreach ($handles as $handle) {
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

    return $best;
  }

  private static function has_modules_for(string $host_prefix): bool {
    foreach (self::$modules as $module) {
      if ($module['host_prefix'] === $host_prefix) {
        return true;
      }
    }

    return false;
  }

  /** Reject a top-level route that would steal all or part of a module route. */
  private static function assert_preserves_module_ownership(ConsumerHandle $candidate): void {
    $candidate_root = trim($candidate->namespace_root(), '\\');
    if ($candidate_root === '') {
      return;
    }

    foreach (self::$modules as $module_root => $module) {
      $host = self::$consumers[$module['host_prefix']];
      $host_root = trim($host->namespace_root(), '\\');
      $module_root = trim($module_root, '\\');

      $ties_or_outranks_host = self::namespace_contains($candidate_root, $module_root)
        && strlen($candidate_root) >= strlen($host_root);
      $partitions_module = self::namespace_contains($module_root, $candidate_root);

      if ($ties_or_outranks_host || $partitions_module) {
        throw new \LogicException(
          "DDD consumer \"{$candidate->prefix()}\" namespace \"$candidate_root\" "
          . "would invalidate or partition module \"$module_root\" owned by consumer \"{$module['host_prefix']}\"",
        );
      }
    }
  }

  private static function namespace_contains(string $root, string $subject): bool {
    return $root !== '' && ($subject === $root || str_starts_with($subject, $root . '\\'));
  }
}
