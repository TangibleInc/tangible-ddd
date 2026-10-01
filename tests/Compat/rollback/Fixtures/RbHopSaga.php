<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Fixtures;

use TangibleDDD\Application\Process\Async;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * Started by hand; first() runs in-band, then the #[Async] second() hops:
 * the row rests `scheduled` with a `{prefix}_process_continue` action
 * (`['process_id' => int]`) until a worker continues it.
 */
class RbHopSaga extends LongProcess {

  public function __construct(public readonly string $order = '') {
    parent::__construct(null);
  }

  protected function first(): Result {
    RbJournal::mark("hop:first:{$this->order}");
    return new Result();
  }

  #[Async]
  protected function second(): Result {
    RbJournal::mark("hop:second:{$this->order}");
    return new Result();
  }
}
