<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitAny;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * D3 any-of with a cancellation fact (process.await-any-cancellation).
 *
 * `prepare` (step 0), then `sync` (step 1): mints a job ref, sends
 * StepCommand('sync', ref) and awaits AwaitAny(the JobFinished keyed on
 * the ref) cancelled by WidgetScrapped of its widget, with a 1 h alarm.
 * `finish_sync` (step 2) runs on the answer. A cancellation compensates
 * `prepare`: `undo_prepare:{widget}:{failure message}`.
 */
final class CancellableJobProcess extends LongProcess {

  public const TIMEOUT_SECONDS = 3600;

  public function __construct(public readonly string $widget_id = 'w-1') {
    parent::__construct(null);
  }

  protected function prepare(): Result {
    ProcessJournal::step("prepare:{$this->widget_id}");
    return new Result(commands: [new StepCommand('prepare', $this->widget_id)]);
  }

  protected function sync(): Result {
    $ref = $this->step_ref('sync');
    ProcessJournal::step("sync:{$this->widget_id}");
    return new Result(
      commands: [new StepCommand('sync', $ref)],
      await: AwaitAny::of(AwaitEvent::keyed(JobFinished::class, $ref))
        ->cancelledBy(new AwaitEvent(WidgetScrapped::class, ['widget_id' => $this->widget_id]))
        ->within(self::TIMEOUT_SECONDS),
    );
  }

  protected function finish_sync(mixed $payload, JobFinished $done): Result {
    ProcessJournal::step("finish_sync:{$this->widget_id}");
    return new Result(commands: [new StepCommand('finish_sync', $this->widget_id)]);
  }

  #[Compensates('prepare')]
  protected function undo_prepare(\Throwable $cause, mixed $checkpoint): Result {
    ProcessJournal::step("undo_prepare:{$this->widget_id}:" . $cause->getMessage());
    return new Result(commands: [new StepCommand('undo_prepare', $this->widget_id)]);
  }
}
