<?php

namespace TangibleDDD\Application\Exceptions;

/**
 * A SelfHandlingCommand's (or SelfHandlingQuery's) handle() asked for an
 * ICommandHandler to be method-injected — a self-handling command wrapping a
 * classic handler, a chimera of the two blessed CQRS shapes. Self-handling
 * exists to KILL the two-class ceremony; delegating handle() to a handler
 * resurrects the ceremony inside the shape that replaced it, and hides a
 * convention-pairable handler from the resolver that owns that pairing.
 *
 * The fix: pick one shape. Either the command carries its own domain logic
 * in handle() (self-handling, no handler class), or it is a plain command
 * paired with its handler through the command bus's handler resolver
 * (two-class shape). Never both.
 */
final class SelfHandlingCommandWrapsHandler extends ApplicationException {

  public function __construct(string $command, string $handler_type) {
    parent::__construct(sprintf(
      '%s is self-handling but its handle() method-injects %s, which is an '
      . 'ICommandHandler — pick one shape: self-handling commands must not '
      . 'wrap command handlers; pair the command with the handler through '
      . 'the command bus instead (plain command + convention/mapped '
      . 'handler), or move the handler\'s logic into handle().',
      $command,
      $handler_type
    ));
  }
}
