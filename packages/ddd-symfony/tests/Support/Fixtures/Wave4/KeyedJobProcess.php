<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/** D3 keyed await: waits for the JobDone keyed on the job id it minted, with a timeout. */
final class KeyedJobProcess extends LongProcess {

  public function __construct(public readonly int $app_id = 1, public readonly int $timeout = 1800) {
    parent::__construct(null);
  }

  protected function order(): Result {
    $job = $this->step_ref('job');
    Trail::note("order:{$this->app_id}");
    return new Result(await: AwaitEvent::keyed(JobDone::class, $job, timeout_seconds: $this->timeout));
  }

  protected function record(mixed $payload, JobDone $done): Result {
    Trail::note("record:{$this->app_id}:" . ($done->ok ? 'ok' : 'failed'));
    if (!$done->ok) {
      throw new \RuntimeException('job failed');
    }
    return new Result();
  }

  #[Compensates('order')]
  protected function unorder(\Throwable $cause, mixed $checkpoint): Result {
    Trail::note("unorder:{$this->app_id}");
    return new Result();
  }
}
