<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress;

use League\Tactician\CommandBus;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\LongProcessCatalog;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IDDDConfig;

/**
 * Attach a separately built module container to an existing DDD consumer.
 *
 * Call this from plugins_loaded priority 30, after the winning framework copy
 * initializes at priority 1 and the host calls boot() (priority 10 by fleet
 * convention). Namespace routing is installed immediately. Listener and
 * LongProcess wiring waits until init priority 3, after host hooks at init:2.
 *
 * Repeating the same host/root before init:3 replaces the module getter while
 * retaining one runtime hook. A conflicting host or a post-wiring replacement
 * fails. The module remains absent from ConsumerRegistry::all() and receives
 * no independent persistence, worker, migration, or dashboard identity.
 *
 * Stateful host services must be imported with ConsumerRegistry::service_for().
 * In particular, the command-bus transaction service ID is an explicit
 * host/module contract; the framework does not guess or reconstruct it.
 * Successful registration also declares a 0.6.2 loader requirement under the
 * stable diagnostic identifier `ddd-module:<normalized namespace root>`.
 *
 * @param string $host_prefix Registered top-level consumer prefix
 * @param string $namespace_root Strict namespace descendant owned by the module
 * @param callable $di_getter Function returning the module runtime container
 */
function boot_module(
  string $host_prefix,
  string $namespace_root,
  callable $di_getter,
): void {
  if (\did_action('init') > 0) {
    throw new \LogicException(
      'DDD modules must be registered before WordPress init begins',
    );
  }

  $root = trim($namespace_root, '\\');
  $state =& module_runtime_state();
  $existing = $state['boots'][$root] ?? null;

  if ($existing !== null) {
    if ($existing['host_prefix'] !== $host_prefix) {
      throw new \InvalidArgumentException(
        "Module namespace \"$root\" is already registered for consumer \"{$existing['host_prefix']}\"",
      );
    }
    if ($existing['wired']) {
      throw new \LogicException("DDD module \"$root\" is already wired at init priority 3");
    }
  }

  ConsumerRegistry::add_module($host_prefix, $root, $di_getter);
  \Tangible_DDD_Versions::instance()->require_version(
    'ddd-module:' . $root,
    '0.6.2',
  );

  if ($existing !== null) {
    return;
  }

  $state['boots'][$root] = [
    'host_prefix' => $host_prefix,
    'wired' => false,
  ];

  add_action('init', static function () use ($host_prefix, $root): void {
    wire_module($host_prefix, $root);
  }, 3);
}

/**
 * Wire one registered module after its host runtime hooks.
 *
 * @internal Public only because WordPress stores procedural callbacks.
 */
function wire_module(string $host_prefix, string $namespace_root): void {
  $state =& module_runtime_state();
  $boot = $state['boots'][$namespace_root] ?? null;

  if ($boot === null || $boot['host_prefix'] !== $host_prefix) {
    throw new \LogicException(
      "DDD module \"$namespace_root\" is not registered for consumer \"$host_prefix\"",
    );
  }
  if ($boot['wired']) {
    return;
  }

  $modules = ConsumerRegistry::modules_for($host_prefix);
  $module = $modules[$namespace_root] ?? null;
  if ($module === null) {
    throw new \LogicException("DDD module route \"$namespace_root\" is missing");
  }

  $container = $module->container();
  validate_module_container_contract($host_prefix, $container);
  $entries = process_entries_from_module_container($container);
  $remaining = validate_module_process_entries(
    $host_prefix,
    $namespace_root,
    $entries,
  );

  $runner = null;
  if ($remaining !== []) {
    $service = ConsumerRegistry::service_for($host_prefix, ProcessRunner::class);
    if (!$service instanceof ProcessRunner) {
      throw new \UnexpectedValueException(
        "DDD consumer \"$host_prefix\" service \"" . ProcessRunner::class . '" is not a ProcessRunner',
      );
    }
    $runner = $service;
  }

  register_event_handlers(static fn (): object => $container, true);

  if ($runner !== null) {
    register_process_entries($module->config(), $runner, $remaining);
  }

  $state['boots'][$namespace_root]['wired'] = true;
}

/** Validate services required by every module before listener constructors run. */
function validate_module_container_contract(string $host_prefix, object $container): void {
  if (!method_exists($container, 'has') || !method_exists($container, 'get')) {
    throw new \UnexpectedValueException(
      'DDD module container must expose has() and get() runtime APIs',
    );
  }

  foreach ([IDDDConfig::class, CommandBus::class] as $service_id) {
    if (!$container->has($service_id)) {
      throw new \UnexpectedValueException(
        "DDD module container is missing mandatory service \"$service_id\"",
      );
    }
  }

  $config = $container->get(IDDDConfig::class);
  if (!$config instanceof IDDDConfig) {
    throw new \UnexpectedValueException(
      'DDD module service "' . IDDDConfig::class . '" is not an IDDDConfig',
    );
  }
  if ($config !== ConsumerRegistry::config_for($host_prefix)) {
    throw new \UnexpectedValueException(
      "DDD module must resolve consumer \"$host_prefix\" exact config object",
    );
  }

  $command_bus = $container->get(CommandBus::class);
  if (!$command_bus instanceof CommandBus) {
    throw new \UnexpectedValueException(
      'DDD module service "' . CommandBus::class . '" is not a CommandBus',
    );
  }
}

/**
 * Validate a module catalog against the immutable host base and prior modules.
 *
 * Exact metadata duplicates are removed from the returned entries. Conflicts
 * fail before listener constructors or process callbacks are registered.
 *
 * @param array<class-string<LongProcess>, list<array<string, mixed>>> $entries
 * @return array<class-string<LongProcess>, list<array<string, mixed>>>
 */
function validate_module_process_entries(
  string $host_prefix,
  string $namespace_root,
  array $entries,
): array {
  $state =& module_runtime_state();

  if (!isset($state['host_catalogs_loaded'][$host_prefix])) {
    $host_entries = process_entries_from_host_container(
      ConsumerRegistry::consumer($host_prefix)->container(),
    );
    $catalog = [];
    foreach ($host_entries as $class => $metadata) {
      assert_process_catalog_entry($class, $metadata);
      $catalog[$class] = [
        'metadata' => $metadata,
        'owner' => "host \"$host_prefix\"",
      ];
    }
    $state['catalogs'][$host_prefix] = $catalog;
    $state['host_catalogs_loaded'][$host_prefix] = true;
  }

  $catalog = $state['catalogs'][$host_prefix] ?? [];
  $next_catalog = $catalog;
  $remaining = [];

  foreach ($entries as $class => $metadata) {
    assert_process_catalog_entry($class, $metadata);
    if (!str_starts_with($class, $namespace_root . '\\')) {
      throw new \InvalidArgumentException(
        "LongProcess \"$class\" is outside module namespace \"$namespace_root\"",
      );
    }

    $prior = $catalog[$class] ?? null;
    if ($prior !== null) {
      if ($prior['metadata'] !== $metadata) {
        throw new \LogicException(
          "LongProcess \"$class\" metadata conflicts with {$prior['owner']}",
        );
      }

      continue;
    }

    $next_catalog[$class] = [
      'metadata' => $metadata,
      'owner' => "module \"$namespace_root\"",
    ];
    $remaining[$class] = $metadata;
  }

  $state['catalogs'][$host_prefix] = $next_catalog;

  return $remaining;
}

/**
 * @return array<class-string<LongProcess>, list<array<string, mixed>>>
 */
function process_entries_from_module_container(object $container): array {
  return process_entries_from_runtime_container($container, false);
}

/**
 * @return array<class-string<LongProcess>, list<array<string, mixed>>>
 */
function process_entries_from_host_container(object $container): array {
  return process_entries_from_runtime_container($container, true);
}

/**
 * @return array<class-string<LongProcess>, list<array<string, mixed>>>
 */
function process_entries_from_runtime_container(
  object $container,
  bool $allow_retained_tags,
): array {
  if (method_exists($container, 'has') && $container->has(LongProcessCatalog::class)) {
    if (!method_exists($container, 'get')) {
      throw new \UnexpectedValueException(
        'Container advertises "' . LongProcessCatalog::class . '" but cannot resolve its process catalog',
      );
    }
    $catalog = $container->get(LongProcessCatalog::class);
    if (!$catalog instanceof LongProcessCatalog) {
      throw new \UnexpectedValueException(
        'Container service "' . LongProcessCatalog::class . '" is not a LongProcessCatalog',
      );
    }

    return $catalog->all();
  }

  if ($allow_retained_tags && method_exists($container, 'findTaggedServiceIds')) {
    $entries = $container->findTaggedServiceIds('ddd.long_process');
    if (!is_array($entries)) {
      throw new \UnexpectedValueException('findTaggedServiceIds() did not return an array');
    }

    return $entries;
  }

  return [];
}

/** @param mixed $metadata */
function assert_process_catalog_entry(mixed $class, mixed $metadata): void {
  if (!is_string($class) || !is_subclass_of($class, LongProcess::class)) {
    $display = is_string($class) ? $class : get_debug_type($class);
    throw new \InvalidArgumentException(
      "$display is catalogued as ddd.long_process but does not extend LongProcess",
    );
  }
  if (!is_array($metadata)) {
    throw new \InvalidArgumentException("LongProcess \"$class\" metadata must be an array");
  }
  foreach ($metadata as $tag) {
    if (!is_array($tag)) {
      throw new \InvalidArgumentException("LongProcess \"$class\" tag metadata must be an array");
    }
  }
}

/**
 * Reset module lifecycle state between isolated tests.
 *
 * @internal
 */
function reset_module_runtime(): void {
  $state =& module_runtime_state();
  $state = [
    'boots' => [],
    'catalogs' => [],
    'host_catalogs_loaded' => [],
  ];
}

/**
 * @return array{
 *   boots: array<string, array{host_prefix: string, wired: bool}>,
 *   catalogs: array<string, array<class-string<LongProcess>, array{metadata: list<array<string, mixed>>, owner: string}>>,
 *   host_catalogs_loaded: array<string, bool>
 * }
 */
function &module_runtime_state(): array {
  static $state = [
    'boots' => [],
    'catalogs' => [],
    'host_catalogs_loaded' => [],
  ];

  return $state;
}
