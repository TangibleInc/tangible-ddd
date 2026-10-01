<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\Async;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * `first`, then an #[Async] `second`: after the first step the process is
 * `scheduled` with one Continue intent for step 1 (`continue:{id}:1`).
 */
final class HopWidgetProcess extends LongProcess {

  public function __construct(public readonly string $widget_id = 'w-1') {
    parent::__construct(null);
  }

  protected function first(): Result {
    ProcessJournal::step('first');
    return new Result(commands: [new StepCommand('first', $this->widget_id)]);
  }

  #[Async]
  protected function second(): Result {
    ProcessJournal::step('second');
    return new Result(commands: [new StepCommand('second', $this->widget_id)]);
  }
}
