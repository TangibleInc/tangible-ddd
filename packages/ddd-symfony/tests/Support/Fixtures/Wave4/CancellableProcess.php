<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4;

use TangibleDDD\Application\Process\AwaitAny;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/** D3 any-of over two fact classes: its keyed job report answers, its app's destruction cancels. */
final class CancellableProcess extends LongProcess {

  public function __construct(public readonly int $app_id = 1) {
    parent::__construct(null);
  }

  protected function prepare(): Result {
    Trail::note("prepare:{$this->app_id}");
    return new Result();
  }

  protected function sync(): Result {
    Trail::note("sync:{$this->app_id}");
    return new Result(await: AwaitAny::of(AwaitEvent::keyed(JobDone::class, $this->step_ref('sync')))
      ->cancelled_by(new AwaitEvent(AppDestroyed::class, ['app_id' => $this->app_id])));
  }

  protected function synced(mixed $payload, JobDone $done): Result {
    Trail::note("synced:{$this->app_id}");
    return new Result();
  }

  #[Compensates('prepare')]
  protected function unprepare(\Throwable $cause, mixed $checkpoint): Result {
    Trail::note("unprepare:{$this->app_id}:" . $cause->getMessage());
    return new Result();
  }
}
