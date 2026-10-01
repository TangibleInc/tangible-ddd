<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;

/** A one-step process ignited by V8Fact (#[StartsOn]); counts its runs. */
#[StartsOn(V8Fact::class)]
class V8IgnitedProcess extends LongProcess {

  public static int $runs = 0;

  public function __construct(public readonly int $request_id = 0) {
    parent::__construct(null);
  }

  public static function from_event(V8Fact $event): ?static {
    return new static($event->n);
  }

  protected function react(): Result {
    self::$runs++;
    return new Result();
  }
}
