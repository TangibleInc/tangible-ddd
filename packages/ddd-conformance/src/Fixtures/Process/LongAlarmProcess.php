<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitAlarm;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * D7 (process.alarm-long): arms a 25 h alarm (step 0), then fires (step 1),
 * which sends StepCommand('fired'). The alarm waits for no fact; its
 * instant is fixed once, at suspension, on a durable Timeout intent.
 */
final class LongAlarmProcess extends LongProcess {

  public const ALARM_SECONDS = 25 * 3600;

  public function __construct(public readonly string $widget_id = 'w-1') {
    parent::__construct(null);
  }

  protected function arm(): Result {
    ProcessJournal::step('arm');
    return new Result(await: AwaitAlarm::after(self::ALARM_SECONDS));
  }

  protected function fire(mixed $payload, mixed $arrival): Result {
    ProcessJournal::step('fire');
    return new Result(commands: [new StepCommand('fired', $this->widget_id)]);
  }
}
