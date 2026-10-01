<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * Step `ask` sends two commands AND suspends on the widget's WidgetPacked
 * (F2: the await commits before the commands dispatch), then `ship` runs.
 */
final class PackWidgetProcess extends LongProcess {

  public function __construct(public readonly string $widget_id = 'w-1') {
    parent::__construct(null);
  }

  protected function ask(): Result {
    ProcessJournal::step('ask');
    return new Result(
      commands: [new StepCommand('ask', $this->widget_id), new StepCommand('ask-2', $this->widget_id)],
      await: new AwaitEvent(WidgetPacked::class, ['widget_id' => $this->widget_id]),
    );
  }

  protected function ship(mixed $payload, WidgetPacked $packed): Result {
    ProcessJournal::step('ship');
    return new Result(commands: [new StepCommand('ship', $packed->widget_id)]);
  }

  #[Compensates('ask')]
  protected function unask(\Throwable $cause, mixed $checkpoint): Result {
    ProcessJournal::step('unask');
    return new Result();
  }
}
