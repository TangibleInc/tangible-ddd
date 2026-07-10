<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Infra\IDDDConfig;

/**
 * Subscribe to an infrastructure event (e.g. 'outbox_dlq', 'process_failed').
 *
 * This is the infra-tier analogue of integration_action(): before invoking the
 * callback it RESTORES the carried trace context — correlation, and causation
 * when present — so the reaction (and any command it dispatches via ->send())
 * rejoins the originating trace instead of being born orphaned. Context is
 * reset afterwards so it cannot bleed into the next entry on the same worker.
 *
 * The callback receives the IInfrastructureEvent; get the machinery fact via
 * $event->subject() (or a typed accessor like ->entry()).
 *
 * @param callable $callback fn(IInfrastructureEvent $event): void
 */
function infrastructure_action(IDDDConfig $config, string $action, callable $callback, int $priority = 10): void {
  add_action($config->hook($action), function (IInfrastructureEvent $event) use ($callback) {
    if ($event->correlation_id() !== null) {
      CorrelationContext::init($event->correlation_id());
    }

    if ($event->causation_id() !== null && $event->causation_type() !== null) {
      CorrelationContext::set_causation($event->causation_id(), $event->causation_type());
    }

    try {
      $callback($event);
    } finally {
      CorrelationContext::reset();
    }
  }, $priority, 1);
}
