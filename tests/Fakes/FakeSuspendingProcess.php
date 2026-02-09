<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

class FakeSuspendingProcess extends LongProcess {
  public array $executed_steps = [];

  public function __construct() {
    parent::__construct(null);
  }

  protected function request_action(): Result {
    $this->executed_steps[] = 'request_action';
    return new Result(
      payload: new FakePayload('requested', 1),
      await: new AwaitEvent(FakeIntegrationEvent::class, ['entity_id' => 42])
    );
  }

  protected function after_action(?FakePayload $payload, FakeIntegrationEvent $event): Result {
    $this->executed_steps[] = 'after_action';
    return new Result(payload: new FakePayload('completed_' . $event->entity_id, 2));
  }
}
