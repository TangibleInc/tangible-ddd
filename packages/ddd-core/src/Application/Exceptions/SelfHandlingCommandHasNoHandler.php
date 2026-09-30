<?php

namespace TangibleDDD\Application\Exceptions;

/**
 * A self-handling class (SelfHandlingCommand or SelfHandlingQuery) reached
 * its bus without declaring the handle() it promises. The whole point of the
 * bases is that the command/query carries its own
 * `protected function handle(...$deps)` — the middleware is that method's
 * only legal caller. No method, nothing to run.
 *
 * The fix: declare `protected function handle(...typed deps)` on the class
 * (its dependencies are method-injected from the container; void-by-default
 * for a command per the receipt rule, returning the read result for a
 * query), or, if this really wants the two-class shape, drop the base and
 * let the naming-convention resolver find a separate handler class.
 *
 * (The class name predates the query twin and stays — renaming a thrown
 * exception is a break with no behavior attached; the message covers both.)
 */
final class SelfHandlingCommandHasNoHandler extends ApplicationException {

  public function __construct(string $command) {
    parent::__construct(sprintf(
      '%s is self-handling (SelfHandlingCommand/SelfHandlingQuery) but '
      . 'declares no handle() method — a self-handling command or query '
      . 'carries its own protected handle(...$deps). Declare one, or drop '
      . 'the base and use a separate handler class.',
      $command
    ));
  }
}
