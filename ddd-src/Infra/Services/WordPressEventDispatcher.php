<?php

namespace TangibleDDD\Infra\Services;

use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * WordPress implementation of domain event dispatcher.
 *
 * Fires domain events as WordPress actions via do_action().
 */
class WordPressEventDispatcher implements IDomainEventDispatcher {
  public function dispatch(IDomainEvent $event): void {
    // do_action_ref_array, not do_action(..., ...$payload): spreading an
    // assoc payload gives do_action() string-keyed variadics, and WP core's
    // preamble reads $arg[0] → "Undefined array key 0" warning on EVERY
    // payload-carrying domain event (plugin.php:517). The ref_array variant
    // skips that preamble and hands WP_Hook the same array — string keys
    // still dispatch as NAMED arguments to callbacks, so the ctor-as-schema
    // named-payload contract is unchanged (0.2.5 rider).
    do_action_ref_array($event::action(), $event->payload());
  }
}
