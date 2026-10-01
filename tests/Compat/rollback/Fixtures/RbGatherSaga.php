<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Compat\Rollback\Fixtures;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * Started by hand; suspends on AwaitAll(RbPartShipped, parts a and b of
 * its order, 1 h alarm, PROCEED): the alarm is an `{prefix}_await_timeout`
 * action with the legacy associative args. assemble() records how many
 * parts arrived.
 */
#[Awaits(RbPartShipped::class)]
class RbGatherSaga extends LongProcess {

  public const TIMEOUT_SECONDS = 3600;

  public function __construct(public readonly string $order = '') {
    parent::__construct(null);
  }

  public static function part_key(RbPartShipped $part): string {
    return $part->order . ':' . $part->part;
  }

  protected function request(): Result {
    RbJournal::mark("gather:request:{$this->order}");
    return new Result(null, [], new AwaitAll(
      RbPartShipped::class,
      ["{$this->order}:a", "{$this->order}:b"],
      [self::class, 'part_key'],
      self::TIMEOUT_SECONDS,
      AwaitAll::TIMEOUT_PROCEED,
    ));
  }

  /** $gather: the satisfied (or timed-out) AwaitAll itself, its resume argument in 0.6 and N. */
  protected function assemble(mixed $payload, mixed $gather): Result {
    RbJournal::mark("gather:assemble:{$this->order}:" . ($gather instanceof AwaitAll ? count($gather->gathered()) : 'none'));
    return new Result();
  }
}
