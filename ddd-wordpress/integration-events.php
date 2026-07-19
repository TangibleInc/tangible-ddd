<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Register an integration event handler with automatic correlation scoping.
 *
 * This wraps add_action() to unwrap the envelope and run the callback inside
 * the fact's trace scope (Correlation::within).
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
    // The drain bracket: unwrap once, open a facade scope with the fact as
    // ambient cause for the WHOLE body.
    $envelope = null;
    if (count($params) === 1 && is_array($params[0]) && isset($params[0]['__correlation_id'])) {
      $envelope = IntegrationEnvelope::unwrap($params[0]);
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
        $envelope?->correlation_id ?? 'none',
        $e->getMessage()
      ));
      throw $e;
    }
  }, $priority, $arg_count);
}

/**
 * Strip the journey keys from ActionScheduler job args.
 *
 * The OutboxProcessor wraps payload and injects __correlation_id and __event_id.
 * This unwraps and returns clean params — scoping is the caller's business
 * (Correlation::within with the envelope's trace_context()).
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

    $ctx = $envelope->trace_context();
    if ($ctx !== null && $envelope->event_id !== null) {
      $ctx = $ctx->for_fact($envelope->event_id, $event_class);
    }

    $run = static function () use ($event_class, $envelope, $translate) {
      $event = $event_class::from_payload($envelope->payload);

      $command = $translate($event);
      $command?->send();
    };

    $ctx !== null ? Correlation::within($ctx, $run) : $run();
  }, 10, 1);
}
