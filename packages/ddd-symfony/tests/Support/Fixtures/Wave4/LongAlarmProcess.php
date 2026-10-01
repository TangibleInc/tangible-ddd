<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4;

use TangibleDDD\Application\Process\AwaitAlarm;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/** D7: a long alarm (absolute UTC instant, or now + seconds), then one step. */
final class LongAlarmProcess extends LongProcess {

  public function __construct(public readonly ?string $at = null, public readonly int $after = 90000) {
    parent::__construct(null);
  }

  protected function wait(): Result {
    Trail::note('wait');
    return new Result(await: $this->at !== null ? AwaitAlarm::at(new \DateTimeImmutable($this->at)) : AwaitAlarm::after($this->after));
  }

  protected function fire(mixed $payload, mixed $arrival): Result {
    Trail::note('fire');
    return new Result();
  }
}
