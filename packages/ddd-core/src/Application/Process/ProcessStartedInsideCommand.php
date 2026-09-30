<?php

namespace TangibleDDD\Application\Process;

use TangibleDDD\Application\Exceptions\ApplicationException;

/**
 * ProcessRunner::start() was called inside a command's bus pass.
 *
 * start() is the EDGE door — REST controllers, CLI, WP hook closures, the
 * drain — where running the first step in-band is safe because the context
 * is flat. Inside a command handler it would execute saga steps (and their
 * dispatched commands) nested in the caller's pass. A handler that wants a
 * saga records the intent as an integration event; the saga declares
 * #[StartsOn(ThatEvent::class)] and ignites at drain time.
 */
final class ProcessStartedInsideCommand extends ApplicationException {

  public function __construct(string $process_class, string $inside) {
    parent::__construct(sprintf(
      '%s started inside %s — record an integration event and give the '
      . 'process #[StartsOn(...)] instead.',
      $process_class,
      $inside
    ));
  }
}
