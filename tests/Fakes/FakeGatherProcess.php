<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

class FakeGatherProcess extends LongProcess {
  public array $executed_steps = [];
  public ?AwaitAll $gather_seen = null;

  public function __construct(public readonly array $request_ids = [1, 2, 3]) {
    parent::__construct(null);
  }

  protected function dispatch(): Result {
    $this->executed_steps[] = 'dispatch';
    return new Result(
      payload: new FakePayload('dispatched', 1),
      await: new AwaitAll(
        event_class: FakeResolvedEvent::class,
        expected: $this->request_ids,
        key_by: [self::class, 'resolution_key'],
        timeout_seconds: 3600,
        on_timeout: AwaitAll::TIMEOUT_PROCEED,
      ),
    );
  }

  protected function evaluate(?FakePayload $payload, AwaitAll $gather): Result {
    $this->executed_steps[] = 'evaluate';
    $this->gather_seen = $gather;
    return new Result(payload: new FakePayload('evaluated', 2));
  }

  public static function resolution_key(FakeResolvedEvent $e): int {
    return $e->request_id;
  }
}
