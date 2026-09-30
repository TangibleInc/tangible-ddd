<?php

namespace TangibleDDD\Application\Exceptions;

/**
 * A command was dispatched while another command's bus pass was still open.
 *
 * Commands are the atomic moments of the system — they never nest. Nesting
 * would run the inner command's middleware inside the outer's (onion inside
 * onion) and, for transactional commands, MySQL's implicit COMMIT on a nested
 * START TRANSACTION silently destroys the outer command's atomicity.
 *
 * The fixes, by intent:
 *  - the outer handler wants a synchronous side effect → call a domain
 *    service in-band, don't dispatch;
 *  - the outer handler wants follow-on work → record an integration event
 *    and let a listener dispatch the command at drain time;
 *  - a saga wants the command → emit it via Result->commands.
 */
final class CommandDispatchedInsideCommand extends ApplicationException {

  public function __construct(string $attempted, string $inside) {
    parent::__construct(sprintf(
      '%s dispatched inside %s — commands never nest. Call a domain service '
      . 'in-band, or record an integration event and react at drain time.',
      $attempted,
      $inside
    ));
  }
}
