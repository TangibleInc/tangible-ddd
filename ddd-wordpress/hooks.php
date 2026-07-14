<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\IDDDConfig;

/**
 * The consumer's whole wiring ceremony in one call: announces the plugin to
 * the consumer registry immediately, and defers register_hooks() to init:2 —
 * after the consumer's DI container compiles on init:1.
 *
 * Call at include time from the main plugin file or plugins_loaded (the
 * generated ddd-wordpress/di/index.php does exactly this).
 *
 * @param IDDDConfig $config Plugin configuration
 * @param callable $di_getter Function that returns the DI container
 * @param string|null $label Human label for discovery surfaces (default: prefix)
 */
function boot(IDDDConfig $config, callable $di_getter, ?string $label = null): void {
  ConsumerRegistry::add($config, $di_getter, $label);

  add_action('init', static function () use ($config, $di_getter): void {
    register_hooks($config, $di_getter);
  }, 2);
}

/**
 * All registered consumers, filtered through `tangible_ddd_consumers`
 * (relabel / hide / inject). Populated by boot()/register_hooks(), so read
 * after init:2 — a dashboard or CLI reading earlier sees only consumers
 * that boot()ed at include time.
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
 * Register all DDD framework hooks.
 *
 * Call this after DI container is compiled.
 *
 * @param IDDDConfig $config Plugin configuration
 * @param callable $di_getter Function that returns the DI container
 */
function register_hooks(IDDDConfig $config, callable $di_getter): void {
  ConsumerRegistry::add($config, $di_getter);
  register_event_handlers($di_getter);
  register_process_hooks($config, $di_getter);
  register_outbox_hooks($config, $di_getter);
  register_migration_hooks($config);
}

/**
 * Eagerly instantiate all event handler and integration listener services so
 * their constructors register WordPress action hooks (add_action).
 *
 * Without this, async handlers (AsyncWordPressActionHandler) and
 * IntegrationListener subclasses never register their callbacks, because
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
 */
function register_event_handlers(callable $di_getter): void {
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

  // Schedule recurring outbox processor
  add_action('init', function() use ($config) {
    if (!as_next_scheduled_action($config->hook('outbox_process'))) {
      as_schedule_recurring_action(
        time(),
        30, // Every 30 seconds
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
 * Register process classes from DI container tags.
 *
 * Call this from 'tgbl_{prefix}_post_compile_di' hook.
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

  foreach ($tagged as $class => $tags) {
    $runner->register($class);

    // Register awaited events declared via #[Awaits(...)] on the class
    foreach ((new \ReflectionClass($class))->getAttributes(\TangibleDDD\Application\Process\Awaits::class) as $attr) {
      $runner->register_event($attr->newInstance()->event_class);
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
