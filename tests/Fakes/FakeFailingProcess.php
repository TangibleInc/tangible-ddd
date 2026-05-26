<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Process\Compensates;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

class FakeFailingProcess extends LongProcess {
  public array $executed_steps = [];

  public function __construct() {
    parent::__construct(null);
  }

  protected function step_one(): Result {
    $this->executed_steps[] = 'step_one';
    return new Result(payload: new FakePayload('one'), checkpoint: new FakePayload('checkpoint_one'));
  }

  protected function step_two(FakePayload $payload): Result {
    $this->executed_steps[] = 'step_two';
    throw new \RuntimeException('Step two failed');
  }

  #[Compensates('step_one')]
  protected function undo_step_one(\Throwable $cause, ?FakePayload $checkpoint): Result {
    $this->executed_steps[] = 'undo_step_one';
    return new Result();
  }
}
