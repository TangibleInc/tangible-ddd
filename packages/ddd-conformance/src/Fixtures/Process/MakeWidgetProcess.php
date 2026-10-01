<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/** `make` (one command), then `finish` (one command). process.crash-mid-step kills it after `make`'s command commits. */
final class MakeWidgetProcess extends LongProcess {

  public function __construct(public readonly string $widget_id = 'w-1') {
    parent::__construct(null);
  }

  protected function make(): Result {
    ProcessJournal::step('make');
    return new Result(commands: [new StepCommand('make', $this->widget_id)]);
  }

  protected function finish(): Result {
    ProcessJournal::step('finish');
    return new Result(commands: [new StepCommand('finish', $this->widget_id)]);
  }
}
