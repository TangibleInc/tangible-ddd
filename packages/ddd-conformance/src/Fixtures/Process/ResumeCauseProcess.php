<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\RetryStep;

/**
 * AW1 (D13, process.resume-cause) and AW2 (the parked-answer scenarios):
 * every step journals the event id of the fact that resumed it,
 * `{step}:{widget}:{event id or -}`.
 *
 * - `order` (step 0) mints a job ref, sends StepCommand('order', ref) and
 *   awaits a JobFinished keyed on it, with no alarm (an answer can be held
 *   off for as long as a scenario needs).
 * - `answer` (step 1) fails `$failures` times and is retried by its
 *   #[RetryStep] policy (a Continue intent, no backoff); then it gathers a
 *   PartArrived for parts a and b of its widget (AwaitAll, a one-day alarm).
 * - `assemble` (step 2) is resumed by the fact that completed the gather.
 * - `finish` (step 3) follows without a fact.
 */
final class ResumeCauseProcess extends LongProcess {

  /** The gather's alarm (AwaitAll requires one): far past any scenario's clock. */
  public const GATHER_TIMEOUT_SECONDS = 86400;

  public static int $failures = 0;

  public function __construct(public readonly string $widget_id = 'w-1') {
    parent::__construct(null);
  }

  protected function order(): Result {
    $ref = $this->step_ref('job');
    $this->note('order');
    return new Result(
      commands: [new StepCommand('order', $ref)],
      await: AwaitEvent::keyed(JobFinished::class, $ref),
    );
  }

  #[RetryStep(attempts: 1)]
  protected function answer(mixed $payload, JobFinished $done): Result {
    $this->note('answer');
    if (self::$failures > 0) {
      self::$failures--;
      throw new \RuntimeException('the answer step failed');
    }
    return new Result(await: new AwaitAll(
      event_class: PartArrived::class,
      expected: ["{$this->widget_id}:a", "{$this->widget_id}:b"],
      key_by: [GatherPartsProcess::class, 'key'],
      timeout_seconds: self::GATHER_TIMEOUT_SECONDS,
    ));
  }

  protected function assemble(mixed $payload, AwaitAll $gathered): Result {
    $this->note('assemble');
    return new Result();
  }

  protected function finish(): Result {
    $this->note('finish');
    return new Result();
  }

  /** The journal entry of $step in this process: `{step}:{widget}:{event id or -}`. */
  public static function entry(string $step, string $widgetId, ?string $eventId): string {
    return "$step:$widgetId:" . ($eventId ?? '-');
  }

  private function note(string $step): void {
    ProcessJournal::step(self::entry($step, $this->widget_id, $this->resumed_by_event_id()));
  }
}
