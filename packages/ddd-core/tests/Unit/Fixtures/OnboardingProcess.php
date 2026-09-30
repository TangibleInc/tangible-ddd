<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/** Only awaits UserJoined (a second awaiter of the same fact as FulfilmentProcess). */
#[Awaits(UserJoined::class)]
final class OnboardingProcess extends LongProcess {

  public function __construct() {
    parent::__construct(null);
  }

  protected function begin(): Result {
    return new Result();
  }
}
