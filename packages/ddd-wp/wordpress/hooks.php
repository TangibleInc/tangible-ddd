<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Application\Process\LongProcessCatalog;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Consumers\ConsumerHandle;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IDDDConfig;

/**
 * A top-level consumer's whole wiring ceremony in one call: announces the
 * plugin to the top-level registry immediately, and defers register_hooks()
 * to init:2, after its DI container compiles on init:1.
 *
 * Sidecars must use boot_module() at plugins_loaded:30. Once a module attaches,
 * this host handle is stable because its exact config and runtime services are
 * shared by every module route.
 *
 * Call only after the winning framework copy initializes at plugins_loaded:1.
 * The generated main-plugin wrapper requires ddd-wordpress/di/index.php at
 * priority 10, and that index calls boot() during its own include.
 *
 * @param IDDDConfig $config Plugin configuration
 * @param callable $di_getter Function that returns the DI container
 * @param string|null $label Human label for discovery surfaces (default: prefix)
 * @param string|null $namespace_root PHP namespace subtree the consumer owns,
 *   for ConsumerRegistry::owner_of() (default: derived from the config
 *   class's namespace — see ConsumerHandle::namespace_root())
 */
function boot(IDDDConfig $config, callable $di_getter, ?string $label = null, ?string $namespace_root = null): void {
  ConsumerRegistry::add($config, $di_getter, $label, $namespace_root);

  add_action('init', static function () use ($config, $di_getter, $label, $namespace_root): void {
    register_hooks($config, $di_getter, $label, $namespace_root);
  }, 2);
}

/**
 * All registered top-level persistence consumers, filtered through
 * `tangible_ddd_consumers`
 * (relabel / hide / inject). Populated by boot()/register_hooks(), so read
 * after init:2 — a dashboard or CLI reading earlier sees only consumers
 * whose post-loader bootstrap has already called boot().
 *
 * @return array<string, ConsumerHandle> prefix => handle
 */
function consumers(): array {
  $handles = ConsumerRegistry::all();

  return function_exists('apply_filters')
    ? apply_filters('tangible_ddd_consumers', $handles)
    : $handles;
}

/**
 * Register all hooks for one top-level DDD consumer.
 *
 * Call this after DI container is compiled. This remains the compatibility
 * entry point for older hosts; separately deployed modules use boot_module()
 * and never call register_hooks() for themselves.
 *
 * @param IDDDConfig $config Plugin configuration
 * @param callable $di_getter Function that returns the DI container
 * @param string|null $label Human label — boot() threads its own through so
 *   the deferred re-registration doesn't clobber it back to the prefix
 * @param string|null $namespace_root Namespace subtree override, same deal
 */
function register_hooks(IDDDConfig $config, callable $di_getter, ?string $label = null, ?string $namespace_root = null): void {
  ConsumerRegistry::add($config, $di_getter, $label, $namespace_root);
  register_event_handlers($di_getter);
  register_process_hooks($config, $di_getter);

  // Prefer the catalog materialized by the DDD compiler pass. Retained
  // ContainerBuilder consumers without that pass keep the tagged fallback.
  if (processes_enabled($config)) {
    $container = $di_getter();
    if (method_exists($container, 'has') && $container->has(LongProcessCatalog::class)) {
      $entries = $container->get(LongProcessCatalog::class)->all();
      if (!empty($entries)) {
        register_process_entries(
          $config,
          $container->get(ProcessRunner::class),
          $entries,
        );
      }
    } elseif (method_exists($container, 'findTaggedServiceIds')) {
      register_processes_from_container($config, $container);
    }
  }

  register_outbox_hooks($config, $di_getter);
  register_migration_hooks($config);
}

/**
 * Eagerly instantiate all event handler and integration listener services so
 * their constructors register WordPress action hooks (add_action).
 *
 * Without this, WordPressActionHandler and IntegrationListener subclasses
 * never register their callbacks, because
 * Symfony DI is lazy — services are only constructed when explicitly
 * requested. Action Scheduler then fails with "no callbacks are registered"
 * when processing queued async jobs.
 *
 * Works by convention: consumer services.yaml registers event handlers under
 * a namespace ending in \Application\EventHandlers\, and integration
 * listeners under \Application\IntegrationListeners\. This function finds all
 * service IDs matching either pattern and instantiates them.
 *
 * @param callable $di_getter Function that returns the DI container
 * @param bool $fail_fast Re-throw construction errors for module boot
 */
function register_event_handlers(callable $di_getter, bool $fail_fast = false): void {
  $container = $di_getter();

  if (!method_exists($container, 'getServiceIds')) {
    return;
  }

  foreach ($container->getServiceIds() as $id) {
    $is_handler  = str_contains($id, '\\Application\\EventHandlers\\');
    $is_listener = str_contains($id, '\\Application\\IntegrationListeners\\');
    if (!$is_handler && !$is_listener) continue;
    if (!class_exists($id)) continue;

    try {
      $container->get($id);
    } catch (\Throwable $e) {
      if ($fail_fast) {
        throw $e;
      }

      error_log(sprintf(
        '[ddd-event-handlers] Failed to boot handler %s: %s',
        $id,
        $e->getMessage()
      ));
    }
  }
}

/**
 * Register process continuation hooks.
 */
function register_process_hooks(IDDDConfig $config, callable $di_getter): void {
  if (!processes_enabled($config)) {
    return;
  }

  add_action($config->hook('process_continue'), function(int $process_id) use ($config, $di_getter) {
    try {
      $container = $di_getter();
      $runner = $container->get(ProcessRunner::class);
      $runner->continue_scheduled($process_id);
    } catch (\Throwable $e) {
      error_log(sprintf(
        '[%s-process] Failed to continue process %d: %s',
        $config->prefix(),
        $process_id,
        $e->getMessage()
      ));
      throw $e;
    }
  });

  // Action Scheduler stores the scheduled args as an associative array
  // (['process_id' => .., 'step_index' => ..]) and fires the callback via
  // call_user_func_array(), which treats string-keyed arrays as named
  // arguments (PHP 8+) — so the callback's parameter names must match the
  // args keys exactly, same convention as process_continue above.
  add_action($config->hook('await_timeout'), function(int $process_id, int $step_index) use ($config, $di_getter) {
    try {
      $runner = ($di_getter())->get(ProcessRunner::class);
      $runner->handle_timeout($process_id, $step_index);
    } catch (\Throwable $e) {
      error_log(sprintf('[%s-process] Await-timeout handling failed for process %d: %s', $config->prefix(), $process_id, $e->getMessage()));
      throw $e;
    }
  }, 10, 2);
}

/**
 * Register outbox processing hooks and cron.
 */
function register_outbox_hooks(IDDDConfig $config, callable $di_getter): void {
  if (!outbox_enabled($config)) {
    return;
  }

  // Schedule recurring outbox processor. Interval comes from OutboxConfig
  // (the <prefix>_outbox_processor_interval option, default 30s) — the knob
  // existed since 0.2.0 but this site hardcoded 30, so the option silently
  // did nothing (0.2.5 rider). NOTE: an already-scheduled action keeps its
  // old cadence — changing the option takes effect after the existing AS
  // action is unscheduled or on a fresh install.
  add_action('init', function() use ($config) {
    if (!as_next_scheduled_action($config->hook('outbox_process'))) {
      as_schedule_recurring_action(
        time(),
        \TangibleDDD\Application\Outbox\OutboxConfig::from_options($config)->processor_interval_seconds,
        $config->hook('outbox_process'),
        [],
        $config->as_group('outbox')
      );
    }
  });

  // Process outbox batch
  add_action($config->hook('outbox_process'), function() use ($config, $di_getter) {
    try {
      $container = $di_getter();
      $processor = $container->get(OutboxProcessor::class);
      $result = $processor->process_batch();

      if ($result->total > 0) {
        error_log(sprintf(
          '[%s-outbox] Processed %d events: %d completed, %d failed, %d moved to DLQ',
          $config->prefix(),
          $result->total,
          $result->completed,
          $result->failed,
          $result->dlq
        ));
      }
    } catch (\Throwable $e) {
      error_log(sprintf(
        '[%s-outbox] Processor error: %s',
        $config->prefix(),
        $e->getMessage()
      ));
    }
  });
}

/**
 * Register process classes from tags on a retained ContainerBuilder.
 *
 * This public function is the 0.6.0 compatibility path. New consumers should
 * register DDDCompilerPasses before compilation and let register_hooks() read
 * LongProcessCatalog, which also works after Symfony dumps the container.
 *
 * Tag format in services.yaml:
 * ```yaml
 * App\Process\MyProcess:
 *   tags:
 *     - name: 'ddd.long_process'
 *       awaits:
 *         - App\Events\SomeEvent
 *         - App\Events\AnotherEvent
 * ```
 *
 * The 'awaits' parameter declares which integration events this process
 * may suspend for. The framework will register action hooks for these
 * events so suspended processes can resume when they fire.
 *
 * @param IDDDConfig $config Plugin configuration
 * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
 * @param string $tag The DI tag for process classes
 */
function register_processes_from_container(
  IDDDConfig $config,
  $container,
  string $tag = 'ddd.long_process'
): void {
  if (!processes_enabled($config)) {
    return;
  }

  $tagged = $container->findTaggedServiceIds($tag);

  if (empty($tagged)) {
    return;
  }

  $runner = $container->get(ProcessRunner::class);

  register_process_entries($config, $runner, $tagged);
}

/**
 * Register process hooks from class names and their ddd.long_process tags.
 *
 * @param array<class-string<\TangibleDDD\Application\Process\LongProcess>, list<array<string, mixed>>> $entries
 */
function register_process_entries(
  IDDDConfig $config,
  ProcessRunner $runner,
  array $entries,
): void {
  if (!processes_enabled($config)) {
    return;
  }

  foreach ($entries as $class => $tags) {
    // Fail fast on a mis-tag: the ddd.long_process tag promises a saga.
    if (!is_subclass_of($class, \TangibleDDD\Application\Process\LongProcess::class)) {
      throw new \InvalidArgumentException("$class is tagged ddd.long_process but does not extend LongProcess");
    }

    // Register awaited events declared via #[Awaits(...)] on the class
    foreach ((new \ReflectionClass($class))->getAttributes(\TangibleDDD\Application\Process\Awaits::class) as $attr) {
      $runner->register_event($attr->newInstance()->event_class);
    }

    // Register ignitions declared via #[StartsOn(...)] — the reactive door:
    // at drain time the event news the process (from_event) and starts it.
    foreach ((new \ReflectionClass($class))->getAttributes(\TangibleDDD\Application\Process\StartsOn::class) as $attr) {
      $runner->register_start($class, $attr->newInstance()->event_class);
    }

    // Register awaited events from tag parameters
    foreach ($tags as $tag_attrs) {
      $awaits = $tag_attrs['awaits'] ?? [];
      foreach ($awaits as $event_class) {
        $runner->register_event($event_class);
      }
    }
  }
}
