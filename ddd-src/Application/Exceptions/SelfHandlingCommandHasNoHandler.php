<?php

namespace TangibleDDD\Application\Exceptions;

/**
 * A SelfHandlingCommand reached the bus without declaring the handle() it
 * promises. The whole point of the base is that the command carries its own
 * `protected function handle(...$deps): void` — the middleware is that
 * method's only legal caller. No method, nothing to run.
 *
 * The fix: declare `protected function handle(...typed deps): void` on the
 * command (its dependencies are method-injected from the container), or, if
 * this really wants the two-class shape, drop `extends SelfHandlingCommand`
 * and let the naming-convention resolver find a separate handler class.
 */
final class SelfHandlingCommandHasNoHandler extends ApplicationException {

  public function __construct(string $command) {
    parent::__construct(sprintf(
      '%s extends SelfHandlingCommand but declares no handle() method — a '
      . 'self-handling command carries its own protected handle(...$deps). '
      . 'Declare one, or drop the base and use a separate handler class.',
      $command
    ));
  }
}
