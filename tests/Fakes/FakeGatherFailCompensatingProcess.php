<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

/**
 * TIMEOUT_FAIL gather saga with a completed step BEFORE the await, plus a
 * registered #[Compensates] method for it — so a timeout actually iterates
 * execute_compensation()'s loop (undo_index >= 0) instead of falling straight
 * through to finish_compensation().
 */
#[Awaits(FakeResolvedEvent::class)]
class FakeGatherFailCompensatingProcess extends LongProcess {
  public array $executed_steps = [];
  public ?FakePayload $checkpoint_seen = null;

  public function __construct(public readonly array $request_ids = [1]) {
    parent::__construct(null);
  }

  protected function reserve(): Result {
    $this->executed_steps[] = 'reserve';
    return new Result(
      payload: new FakePayload('reserved', 1),
      checkpoint: new FakePayload('reserve_checkpoint', 1),
    );
  }

  protected function dispatch(?FakePayload $payload): Result {
    $this->executed_steps[] = 'dispatch';
    return new Result(
      payload: new FakePayload('dispatched', 2),
      await: new AwaitAll(
        event_class: FakeResolvedEvent::class,
        expected: $this->request_ids,
        key_by: [self::class, 'resolution_key'],
        timeout_seconds: 3600,
        on_timeout: AwaitAll::TIMEOUT_FAIL,
      ),
    );
  }

  protected function evaluate(?FakePayload $payload, AwaitAll $gather): Result {
    $this->executed_steps[] = 'evaluate';
    return new Result(payload: new FakePayload('evaluated', 3));
  }

  #[Compensates('reserve')]
  protected function undo_reserve(\Throwable $cause, ?FakePayload $checkpoint): Result {
    $this->executed_steps[] = 'undo_reserve';
    $this->checkpoint_seen = $checkpoint;
    return new Result();
  }

  public static function resolution_key(FakeResolvedEvent $e): int {
    return $e->request_id;
  }
}
