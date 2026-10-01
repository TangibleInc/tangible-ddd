<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\Consumers\IntegrationHookName;

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

  $action = IntegrationHookName::resolve($event_class);
  if ($action === null) {
    IntegrationHookName::note_absent($event_class, 'integration_action');
    return;
  }

  // Schema v8: the callback is a ledgered DDD subscriber (isolated,
  // budgeted); see WpLedgeredDelivery. One attempt, as in 0.6, unless the
  // consumer opts in ({prefix}_ddd_delivery_attempts) or the callback
  // declares #[Retries(n)]; retries go through {prefix}_ddd_redeliver.
  $invoke = function(...$params) use ($callback, $event_class) {
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
  };

  $ledgered = \TangibleDDD\WordPress\Adapter\WpLedgeredDelivery::class;
  add_action(
    $action,
    $ledgered::bind($action, $event_class, $ledgered::subscriber_id($action, 'action', $callback), $priority, $invoke, null, Retries::of($callback)?->attempts()),
    $priority,
    $arg_count
  );
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

  $action = IntegrationHookName::resolve($event_class);
  if ($action === null) {
    IntegrationHookName::note_absent($event_class, 'integration_listener');
    return;
  }

  $invoke = function (array $wrapped) use ($event_class, $translate) {
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
  };

  // The listener's identity is its class (IntegrationListener passes a
  // closure bound to itself), stable across requests and releases.
  $this_ = $translate instanceof \Closure ? (new \ReflectionFunction($translate))->getClosureThis() : null;
  $ledgered = \TangibleDDD\WordPress\Adapter\WpLedgeredDelivery::class;
  add_action(
    $action,
    $ledgered::bind($action, $event_class, $ledgered::subscriber_id($action, 'listener', $translate, $this_ !== null ? get_class($this_) : null), 10, $invoke, null, Retries::of($this_ ?? $translate)?->attempts()),
    10,
    1
  );
}
