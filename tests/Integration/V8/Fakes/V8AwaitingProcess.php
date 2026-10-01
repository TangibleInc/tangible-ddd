<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * Two steps: `wait` suspends on V8Fact #n with a one-hour timeout that
 * PROCEEDS, `finish` counts how often it ran.
 */
class V8AwaitingProcess extends LongProcess {

  public static int $finished = 0;

  public function __construct(public readonly int $n = 1) {
    parent::__construct(null);
  }

  public static function key(V8Fact $e): int {
    return $e->n;
  }

  protected function wait(): Result {
    return new Result(await: new AwaitAll(
      event_class: V8Fact::class,
      expected: [$this->n],
      key_by: [self::class, 'key'],
      timeout_seconds: 3600,
      on_timeout: AwaitAll::TIMEOUT_PROCEED,
    ));
  }

  protected function finish(): Result {
    self::$finished++;
    return new Result();
  }
}
