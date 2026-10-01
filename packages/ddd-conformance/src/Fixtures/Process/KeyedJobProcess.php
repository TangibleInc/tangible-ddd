<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\IAwaitMechanism;
use TangibleDDD\Application\Process\IPrecheckAwait;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\PrecheckSatisfied;
use TangibleDDD\Application\Process\Result;

/**
 * D3 keyed await with a register-then-check precheck (process.await-keyed-precheck).
 *
 * `order` (step 0) mints a job ref (step_ref('job')), sends
 * StepCommand('order', ref) (the job order, carrying the ref), checkpoints
 * the ref and awaits a JobFinished keyed on it, with a 1 h alarm.
 * `record` (step 1) journals `record:{widget}:fact` or
 * `record:{widget}:precheck` and sends StepCommand('record', widget).
 *
 * Precheck: the job's owner publishes its state as the scenario row
 * `job-done:{ref}` (ProcessJournal::markRow() / hasRow()); when that row is
 * committed by the time the runner checks, the await is satisfied in place.
 */
final class KeyedJobProcess extends LongProcess implements IPrecheckAwait {

  public const TIMEOUT_SECONDS = 3600;

  public function __construct(public readonly string $widget_id = 'w-1') {
    parent::__construct(null);
  }

  public static function doneRow(string $ref): string {
    return "job-done:$ref";
  }

  protected function order(): Result {
    $ref = $this->step_ref('job');
    ProcessJournal::step("order:{$this->widget_id}");
    return new Result(
      commands: [new StepCommand('order', $ref)],
      await: AwaitEvent::keyed(JobFinished::class, $ref, timeout_seconds: self::TIMEOUT_SECONDS),
      checkpoint: new StepNote($ref),
    );
  }

  protected function record(mixed $payload, mixed $arrival): Result {
    ProcessJournal::step("record:{$this->widget_id}:" . ($arrival instanceof JobFinished ? 'fact' : 'precheck'));
    return new Result(commands: [new StepCommand('record', $this->widget_id)]);
  }

  public function already_satisfied(IAwaitMechanism $await): ?PrecheckSatisfied {
    $ref = $await instanceof AwaitEvent ? $await->await_key : null;
    return $ref !== null && ProcessJournal::hasRow(self::doneRow($ref)) ? PrecheckSatisfied::with('precheck') : null;
  }
}
