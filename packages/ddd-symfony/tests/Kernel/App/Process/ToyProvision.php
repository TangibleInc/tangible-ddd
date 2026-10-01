<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Process;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\RetryStep;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\CancelToyJobCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\ChargeToyCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\OrderToyJobCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyJobFinished;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\ToyRequested;

/**
 * The E section 10 toy saga, as the TXP process-kernel slice will use the
 * library (D1, D3, D7, D13):
 *
 * - started from a fact (#[StartsOn], ignition gate: one saga per fact);
 * - `order` mints the job id with step_ref() (D13), awaits the ToyJobFinished
 *   keyed on it (D3) with a 30 min timeout alarm (D7), and dispatches the
 *   order after the await committed (persist before dispatch);
 * - `charge` performs a D1 external effect keyed on its own step ref; its
 *   retry policy re-runs the step once, and the journal makes the re-run
 *   reuse the performed result;
 * - a failure after the retry compensates `order`.
 *
 * Discovered as a service (the app's resource loading); the bundle
 * autoconfigures LongProcess subclasses with `ddd.long_process`.
 */
#[StartsOn(ToyRequested::class)]
#[Awaits(ToyJobFinished::class)]
final class ToyProvision extends LongProcess {

  public function __construct(public readonly string $team_id, public readonly int $amount = 500) {
    parent::__construct(null);
  }

  public static function from_event(ToyRequested $event): ?static {
    return new static($event->team_id);
  }

  protected function order(): Result {
    $job = $this->step_ref('job');
    return new Result(
      commands: [new OrderToyJobCommand($this->team_id, $job, (int) $this->get_id(), $this->current_step_index())],
      await: AwaitEvent::keyed(ToyJobFinished::class, $job, timeout_seconds: 1800),
    );
  }

  #[RetryStep(attempts: 1)]
  protected function charge(mixed $payload, ToyJobFinished $done): Result {
    if (!$done->ok) {
      throw new \RuntimeException("toy job {$done->job_id} failed");
    }
    return new Result(commands: [new ChargeToyCommand($this->team_id, $this->amount, $this->step_ref('charge'))]);
  }

  #[Compensates('order')]
  protected function cancel_order(\Throwable $cause, mixed $checkpoint): Result {
    return new Result(commands: [new CancelToyJobCommand($this->team_id, $cause->getMessage())]);
  }
}
