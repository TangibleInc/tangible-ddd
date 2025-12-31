<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Application\Outbox\OutboxProcessor;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\IDDDConfig;

/**
 * Register all DDD framework hooks.
 *
 * Call this after DI container is compiled.
 *
 * @param IDDDConfig $config Plugin configuration
 * @param callable $di_getter Function that returns the DI container
 */
function register_hooks(IDDDConfig $config, callable $di_getter): void {
  register_process_hooks($config, $di_getter);
  register_outbox_hooks($config, $di_getter);
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

    // Register awaited events from tag parameters
    foreach ($tags as $tag_attrs) {
      $awaits = $tag_attrs['awaits'] ?? [];
      foreach ($awaits as $event_class) {
        $runner->register_event($event_class);
      }
    }
  }
}
