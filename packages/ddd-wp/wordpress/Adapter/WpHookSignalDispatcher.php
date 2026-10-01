<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;

/**
 * The wp IInfrastructureSignalDispatcher (register 1.4, 3.9): fires the two
 * 0.6 actions, the per-consumer `{prefix}_{action}` (through the consumer's
 * own IDDDConfig::hook() when it has one) with the event, and the global
 * `tangible_ddd_{action}` with the event and the prefix (R4: names frozen).
 *
 * Error behaviour: a listener that throws propagates, as do_action() did in
 * 0.6; InfrastructureEvent::dispatch() catches and logs it so the emitting
 * machinery is never broken by a monitor.
 */
final class WpHookSignalDispatcher implements IInfrastructureSignalDispatcher {

  public function emit(IInfrastructureEvent $e, IConsumerIdentity $c): void {
    if (!function_exists('do_action')) {
      return;
    }

    $hook = $c instanceof IDDDConfig ? $c->hook($e::action()) : $c->prefix() . '_' . $e::action();
    do_action($hook, $e);
    do_action('tangible_ddd_' . $e::action(), $e, $c->prefix());
  }
}
