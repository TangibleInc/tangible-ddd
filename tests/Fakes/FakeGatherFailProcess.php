<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\Result;

/**
 * Identical to FakeGatherProcess but on_timeout: TIMEOUT_FAIL — used to
 * exercise the compensation path of ProcessRunner::handle_timeout().
 */
class FakeGatherFailProcess extends FakeGatherProcess {
  protected function dispatch(): Result {
    $this->executed_steps[] = 'dispatch';
    return new Result(
      payload: new FakePayload('dispatched', 1),
      await: new AwaitAll(
        event_class: FakeResolvedEvent::class,
        expected: $this->request_ids,
        key_by: [self::class, 'resolution_key'],
        timeout_seconds: 3600,
        on_timeout: AwaitAll::TIMEOUT_FAIL,
      ),
    );
  }
}
