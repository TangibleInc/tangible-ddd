<?php

namespace TangibleDDD\WordPress;

use TangibleDDD\Application\Correlation\Cause;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Infra\IDDDConfig;

/**
 * Subscribe to an infrastructure event (e.g. 'outbox_dlq', 'process_failed').
 *
 * This is the infra-tier analogue of integration_action(): the callback runs
 * inside the CARRIED trace — a Correlation::within() scope built from the
 * event's correlation and causation — so the reaction (and any command it
 * dispatches via ->send()) rejoins the originating trace instead of being
 * born orphaned. An event with no correlation runs flat: causation alone
 * cannot anchor a scope.
 *
 * The callback receives the IInfrastructureEvent; get the machinery fact via
 * $event->subject() (or a typed accessor like ->entry()).
 *
 * @param callable $callback fn(IInfrastructureEvent $event): void
 */
function infrastructure_action(IDDDConfig $config, string $action, callable $callback, int $priority = 10): void {
  add_action($config->hook($action), function (IInfrastructureEvent $event) use ($callback) {
    if (null === $correlation = $event->correlation_id()) {
      $callback($event);
      return;
    }

    $cause = null;
    if ($event->causation_id() !== null && $event->causation_type() !== null) {
      // The at-rest dialect, mapped back to the Kind (the write-side inverse
      // of Cause::causation_type()).
      $cause = new Cause($event->causation_id(), match ($event->causation_type()) {
        'command' => Kind::Act,
        'long_process' => Kind::Trajectory,
        default => Kind::Fact,
      });
    }

    Correlation::within(
      new TraceContext($correlation, $cause),
      static fn () => $callback($event)
    );
  }, $priority, 1);
}
