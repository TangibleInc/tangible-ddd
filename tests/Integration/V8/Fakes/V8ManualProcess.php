<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/** A one-step process with no #[StartsOn]: only ever started by hand. */
class V8ManualProcess extends LongProcess {

  public function __construct(public readonly int $request_id = 0) {
    parent::__construct(null);
  }

  protected function react(): Result {
    return new Result();
  }
}
