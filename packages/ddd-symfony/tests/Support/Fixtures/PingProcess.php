<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\StartsOn;

/** Started by and awaiting PingFact (ignition before resume, S5). */
#[StartsOn(PingFact::class)]
#[Awaits(PingFact::class)]
abstract class PingProcess extends LongProcess {

  public static function from_event(PingFact $event): ?static {
    return null;
  }
}
