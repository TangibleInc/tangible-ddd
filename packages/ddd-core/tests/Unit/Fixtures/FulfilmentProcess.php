<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;

/** Ignites on OrderPlaced and awaits UserJoined (both declarations read by reflection). */
#[StartsOn(OrderPlaced::class)]
#[Awaits(UserJoined::class)]
final class FulfilmentProcess extends LongProcess {

  public function __construct(public readonly int $order_id = 0) {
    parent::__construct(null);
  }

  public static function from_event(OrderPlaced $event): ?static {
    return new static($event->order_id);
  }

  protected function begin(): Result {
    return new Result();
  }
}
