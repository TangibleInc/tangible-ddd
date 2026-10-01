<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Application\Process\LongProcessCatalog;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Consumers\ConsumerHandle;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IDDDConfig;

// ddd-wp init (register 1.3 R2): the winner includes this file from its
// initializer inside WordPress, before any consumer container compiles, so
// the 0.6 constructors' optional port parameters resolve to the transitional
// WordPress adapters. Outside WordPress (no hook system) HostDefaults stays
// empty, as the register requires; test harnesses call register() themselves.
if (function_exists('add_action')) {
  \TangibleDDD\WordPress\Adapter\HostDefaultsWiring::register();
  // After the winner's self-consume hook builds the container (pri 20).
  add_action('plugins_loaded', __NAMESPACE__ . '\\register_self_consumer', 21, 0);
}

/**
 * Register the framework's own consumer (prefix `tangible_ddd`, namespace
 * root `TangibleDDD\Application\Commands`) in ConsumerRegistry, so the core
 * Command base (replay/discard/retry/purge) resolves tangible_ddd's bus
 * through owner_of() (register 1.4: Command no longer calls
 * SelfConsumer\di() itself). Defensive like the self-consume hook: a
 * failure here logs and never fatals the site.
 *
 * @param callable|null $di_getter the self-consumer container getter
 *   (default: SelfConsumer\di(), defined by self/index.php)
 */
function register_self_consumer(?callable $di_getter = null): void {
  $di_getter ??= function_exists(__NAMESPACE__ . '\\SelfConsumer\\di')
    ? static fn () => \TangibleDDD\WordPress\SelfConsumer\di()
    : null;
  if ($di_getter === null) {
    return;
  }

  try {
    $container = $di_getter();
    if ($container === null || !$container->has(IDDDConfig::class)) {
      return;
    }
    ConsumerRegistry::add(
      $container->get(IDDDConfig::class),
      $di_getter,
      'tangible_ddd',
      'TangibleDDD\\Application\\Commands',
    );
  } catch (\Throwable $e) {
    error_log('[ddd-self] self-consumer registration failed: ' . $e->getMessage());
  }
}

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
  register_delivery_hooks($config);
  register_migration_hooks($config);
}

/**
 * Register the handler-retry hook `{prefix}_ddd_redeliver` (register 3.6,
 * 5.1; schema v8): Action Scheduler runs it with ['hook', 'event_class',
 * 'payload'] to re-run the DDD subscribers of one fact that failed, through
 * the delivery ledger (WpLedgeredDelivery). It has no callback under 0.6,
 * so pending redeliveries are lost on a rollback unless
 * `wp ddd drain --before-rollback` ran first. Once per prefix.
 */
function register_delivery_hooks(IDDDConfig $config): void {
  // Named parameters $hook, $event_class, $payload match the action's args keys.
  $callback = [\TangibleDDD\WordPress\Adapter\WpLedgeredDelivery::class, 'redeliver'];
  // Redeliveries are scheduled on this config's hook() and as_group('outbox').
  \TangibleDDD\WordPress\Adapter\WpLedgeredDelivery::registerConsumer($config);
  if (has_action($config->hook('ddd_redeliver'), $callback) === false) {
    add_action($config->hook('ddd_redeliver'), $callback, 10, 3);
  }
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

  // Schema v8: each wake brackets its intent rows (firing → done, or back
  // to pending with the error so a relay tick re-projects it). On the 0.6
  // schema WpWakeBracket just runs the wake.
  add_action($config->hook('process_continue'), function(int $process_id) use ($config, $di_getter) {
    try {
      \TangibleDDD\WordPress\Adapter\WpWakeBracket::run($config, \TangibleDDD\Runtime\Scheduling\WakeKind::Continue, $process_id, null, static function () use ($di_getter, $process_id): void {
        $container = $di_getter();
        $runner = $container->get(ProcessRunner::class);
        $runner->continue_scheduled($process_id);
      });
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
      \TangibleDDD\WordPress\Adapter\WpWakeBracket::run($config, \TangibleDDD\Runtime\Scheduling\WakeKind::Timeout, $process_id, $step_index, static function () use ($di_getter, $process_id, $step_index): void {
        $runner = ($di_getter())->get(ProcessRunner::class);
        $runner->handle_timeout($process_id, $step_index);
      });
    } catch (\Throwable $e) {
      error_log(sprintf('[%s-process] Await-timeout handling failed for process %d: %s', $config->prefix(), $process_id, $e->getMessage()));
      throw $e;
    }
  }, 10, 2);

  // Schema v8 only: ResumeRetry intents ({prefix}_ddd_wakeup, ['key' => …]).
  // No 0.6 callback exists for this hook, so these are lost on a rollback,
  // like {prefix}_ddd_redeliver (register 3.6).
  add_action($config->hook('ddd_wakeup'), function(string $key) use ($config, $di_getter) {
    try {
      \TangibleDDD\WordPress\Adapter\WpWakeBracket::resumeRetry($config, $key, static fn () => ($di_getter())->get(ProcessRunner::class));
    } catch (\Throwable $e) {
      error_log(sprintf('[%s-process] Wakeup %s failed: %s', $config->prefix(), $key, $e->getMessage()));
      throw $e;
    }
  }, 10, 1);
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

  // Process outbox batch: one relay tick (WpRelayTick). On a schema v8
  // consumer with the framework outbox, the fenced port-form relay over
  // Action Scheduler, then wakeup re-projection and the stranded scan;
  // otherwise the container's 0.6-form OutboxProcessor, as before.
  add_action($config->hook('outbox_process'), function() use ($config, $di_getter) {
    try {
      $container = $di_getter();
      $tick = \TangibleDDD\WordPress\Adapter\WpRelayTick::for($config, $container)->run();
      foreach ($tick->errors as $step => $message) {
        error_log(sprintf('[%s-outbox] relay tick %s error: %s', $config->prefix(), $step, $message));
      }
      $result = $tick->relay;

      if ($result !== null && $result->total > 0) {
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
