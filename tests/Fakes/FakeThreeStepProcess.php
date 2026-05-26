<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

class FakeThreeStepProcess extends LongProcess {
  /** @var string[] Track which steps executed */
  public array $executed_steps = [];

  public function __construct() {
    parent::__construct(null);
  }

  protected function initialize(): Result {
    $this->executed_steps[] = 'initialize';
    return new Result(payload: new FakePayload('initialized', 1));
  }

  protected function process_data(FakePayload $payload): Result {
    $this->executed_steps[] = 'process_data';
    return new Result(payload: new FakePayload($payload->data . '+processed', $payload->counter + 1));
  }

  protected function finalize(FakePayload $payload): Result {
    $this->executed_steps[] = 'finalize';
    return new Result(payload: new FakePayload($payload->data . '+finalized', $payload->counter + 1));
  }
}
