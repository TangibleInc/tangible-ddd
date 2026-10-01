<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;

/** Ignites on WidgetOrdered (declines an empty widget id) and runs one step. */
#[StartsOn(WidgetOrdered::class)]
final class OrderedWidgetProcess extends LongProcess {

  public function __construct(public readonly string $widget_id = 'w-1') {
    parent::__construct(null);
  }

  public static function from_event(WidgetOrdered $event): ?static {
    return $event->widget_id === '' ? null : new static($event->widget_id);
  }

  protected function open(): Result {
    ProcessJournal::step('open:' . $this->widget_id);
    return new Result(commands: [new StepCommand('open', $this->widget_id)]);
  }
}
