<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;

#[StartsOn(FakeResolvedEvent::class)]
class FakeStartsOnProcess extends LongProcess {

  /** @var string[] step log, readable post-run via the repository instance */
  public array $executed_steps = [];

  public function __construct(public readonly int $request_id = 0) {
    parent::__construct(null);
  }

  /** Policy filter: declines outcomes it doesn't care about. */
  public static function from_event(FakeResolvedEvent $event): ?static {
    if ($event->outcome === FakeOutcome::Rejected) {
      return null; // not my business
    }
    return new static($event->request_id);
  }

  protected function react(): Result {
    $this->executed_steps[] = "react:{$this->request_id}";
    return new Result();
  }
}
