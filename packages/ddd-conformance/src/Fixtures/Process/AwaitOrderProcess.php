<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/** Suspends on the WidgetOrdered of its widget, then runs `after_order` (the resume). */
final class AwaitOrderProcess extends LongProcess {

  public function __construct(public readonly string $widget_id = 'w-1') {
    parent::__construct(null);
  }

  protected function wait_for_order(): Result {
    ProcessJournal::step('wait_for_order:' . $this->widget_id);
    return new Result(await: new AwaitEvent(WidgetOrdered::class, ['widget_id' => $this->widget_id]));
  }

  protected function after_order(mixed $payload, WidgetOrdered $ordered): Result {
    ProcessJournal::step('after_order:' . $ordered->widget_id);
    return new Result(commands: [new StepCommand('after_order', $ordered->widget_id)]);
  }
}
