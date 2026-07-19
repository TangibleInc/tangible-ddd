<?php

namespace TangibleDDD\WordPress;

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
    $params = extract_correlation($params);

    try {
      $callback(...$params);
    } catch (\Throwable $e) {
      error_log(sprintf(
        '[DDD Integration] [%s] [correlation:%s]: %s',
        $event_class::name(),
        CorrelationContext::peek() ?? 'none',
        $e->getMessage()
      ));
      throw $e;
    } finally {
      // The drain armed the event as causation for its whole body (scope, not
      // one-shot); teardown here so nothing bleeds into the worker's next action.
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
    $envelope->restore_context();

    try {
      $event = $event_class::from_payload($envelope->payload);
      if ($envelope->event_id !== null) {
        $event->stamp_journey((string) $envelope->correlation_id, $envelope->event_id);
      }

      $command = $translate($event);
      $command?->send();
    } finally {
      CorrelationContext::clear_causation();   // scope teardown — see integration_action()
    }
  }, 10, 1);
}
