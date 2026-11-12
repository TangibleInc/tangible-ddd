<?php

namespace TangibleDDD\Application\Intents;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\TraceContext;

/**
 * A command someone meant to send, with the trace context it was born in.
 */
class Intent {

  public function __construct(
    public readonly ICommand $command,
    public readonly TraceContext $born_in,
  ) {}
}
