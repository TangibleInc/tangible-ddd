<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Register an integration event handler with automatic correlation extraction.
 *
 * This wraps add_action() to automatically extract and restore correlation
 * context from ActionScheduler job arguments.
 *
 * @param string $event_class The IntegrationEvent class name
 * @param callable $callback Handler receiving the event payload params
 * @param int $priority WordPress action priority (default 10)
 * @param int $arg_count Number of arguments the callback expects (default 1)
 */
function integration_action(
  string   $event_class,
  callable $callback,
  int      $priority = 10,
  int      $arg_count = 1
): void {
  if (!is_a($event_class, IIntegrationEvent::class, true)) {
    throw new \InvalidArgumentException("$event_class must implement IIntegrationEvent");
  }

  $action = $event_class::integration_action();

  add_action($action, function(...$params) use ($callback, $event_class) {
    // The drain bracket (0.3): unwrap once, open a REAL facade scope with
    // the fact as ambient cause for the WHOLE body. restore_context() stays
    // as the legacy dual-write (wake-lane absorb + un-migrated readers);
    // it dies with the dissolution.
    $envelope = null;
    if (count($params) === 1 && is_array($params[0]) && isset($params[0]['__correlation_id'])) {
      $envelope = IntegrationEnvelope::unwrap($params[0]);
      $envelope->restore_context();
      $params = array_is_list($envelope->payload) ? array_values($envelope->payload) : [$envelope->payload];
    }

    $ctx = $envelope?->trace_context();
    if ($ctx !== null && $envelope->event_id !== null) {
      $ctx = $ctx->for_fact($envelope->event_id, $event_class);
    }

    $run = static fn () => $callback(...$params);

    try {
      $ctx !== null ? Correlation::within($ctx, $run) : $run();
    } catch (\Throwable $e) {
      error_log(sprintf(
        '[DDD Integration] [%s] [correlation:%s]: %s',
        $event_class::name(),
        CorrelationContext::peek() ?? 'none',
        $e->getMessage()
      ));
      throw $e;
    } finally {
      // legacy teardown (scope, not one-shot) — dies with the dissolution
      CorrelationContext::clear_causation();
    }
  }, $priority, $arg_count);
}

/**
 * Extract correlation context from ActionScheduler job args.
 *
 * The OutboxProcessor wraps payload and injects __correlation_id and __event_id.
 * This extracts correlation, initializes context, and returns clean params.
 *
 * @internal
 */
function extract_correlation(array $params): array {
  if (
    count($params) === 1 &&
    is_array($params[0]) &&
    isset($params[0]['__correlation_id'])
  ) {
    $envelope = IntegrationEnvelope::unwrap($params[0]);
    $envelope->restore_context();

    // Positional list payloads spread as positional args; associative payloads
    // pass through intact as a single arg (see array_is_list gate rationale).
    return array_is_list($envelope->payload) ? array_values($envelope->payload) : [$envelope->payload];
  }

  return $params;
}

/**
 * The integration-listener ceremony: hook a record's integration action,
 * rebuild the typed event, restore journey context, translate to a Command.
 *
 * This is the internal primitive; the paved road is the IntegrationListener
 * base class (named, enumerable, DI-constructed). Fn-form = escape hatch.
 *
 * @param class-string<\TangibleDDD\Domain\Events\IIntegrationEvent> $event_class
 * @param callable(\TangibleDDD\Domain\Events\IIntegrationEvent): ?\TangibleDDD\Application\Commands\ICommand $translate
 */
function integration_listener(string $event_class, callable $translate): void {
  if (!is_a($event_class, IIntegrationEvent::class, true)) {
    throw new \InvalidArgumentException("$event_class must implement IIntegrationEvent");
  }

  add_action($event_class::integration_action(), function (array $wrapped) use ($event_class, $translate) {
    $envelope = IntegrationEnvelope::unwrap($wrapped);
    $envelope->restore_context();   // legacy dual-write — dies with the dissolution

    $ctx = $envelope->trace_context();
    if ($ctx !== null && $envelope->event_id !== null) {
      $ctx = $ctx->for_fact($envelope->event_id, $event_class);
    }

    $run = static function () use ($event_class, $envelope, $translate) {
      $event = $event_class::from_payload($envelope->payload);
      if ($envelope->event_id !== null) {
        $event->stamp_journey((string) $envelope->correlation_id, $envelope->event_id);
      }

      $command = $translate($event);
      $command?->send();
    };

    try {
      $ctx !== null ? Correlation::within($ctx, $run) : $run();
    } finally {
      CorrelationContext::clear_causation();   // legacy teardown — dies with the dissolution
    }
  }, 10, 1);
}
