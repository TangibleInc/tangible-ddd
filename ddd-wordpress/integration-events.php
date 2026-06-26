<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Application\Correlation\CorrelationContext;
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
    $wrapped = $params[0];
    CorrelationContext::init($wrapped['__correlation_id']);

    if (isset($wrapped['__sequence'])) {
      CorrelationContext::set_sequence((int) $wrapped['__sequence']);
    }

    // The triggering event is the causation of whatever command this handler
    // dispatches (choreography). Stash it before stripping the transport keys —
    // this is the __event_id that used to be discarded here.
    if (isset($wrapped['__event_id'])) {
      CorrelationContext::set_causation((string) $wrapped['__event_id'], 'integration_event');
    }

    unset($wrapped['__correlation_id'], $wrapped['__sequence'], $wrapped['__event_id']);

    // Positional list payloads (the original contract — every existing consumer,
    // e.g. all of tangible-cred) spread as positional args. Associative payloads
    // pass through intact as a single arg, instead of being silently reindexed
    // to positional by array_values(). Gated on array_is_list() so the list case
    // is byte-for-byte unchanged — backwards compatible.
    return array_is_list($wrapped) ? array_values($wrapped) : [$wrapped];
  }

  return $params;
}
