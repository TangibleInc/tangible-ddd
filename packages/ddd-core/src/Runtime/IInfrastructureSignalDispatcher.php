<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Infra\IConsumerIdentity;

/**
 * Out-of-band machinery signals (dead letter, failed attempt, failed
 * process/workflow, audit sink failure) (register 3.9).
 * `InfrastructureEvent::dispatch()` routes here via HostDefaults from wave 2.
 *
 * Signals are never silent: the core default (LoggingSignalDispatcher) logs;
 * wp fires both `{prefix}_{action}` and `tangible_ddd_{action}`.
 *
 * Error behaviour: must not throw into the machinery that emits the signal
 * (the substrate may be the thing that failed); implementations catch and
 * log their own failures.
 */
interface IInfrastructureSignalDispatcher {
  public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void;
}
