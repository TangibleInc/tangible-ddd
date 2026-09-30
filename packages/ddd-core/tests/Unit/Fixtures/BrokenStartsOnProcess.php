<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;

/** Declares #[StartsOn] but has no static from_event(): a registration error. */
#[StartsOn(OrderPlaced::class)]
final class BrokenStartsOnProcess extends LongProcess {

  public function __construct() {
    parent::__construct(null);
  }

  protected function begin(): Result {
    return new Result();
  }
}
