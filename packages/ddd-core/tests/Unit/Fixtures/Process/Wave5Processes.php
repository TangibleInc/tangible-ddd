<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures\Process;

use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\RetryStep;
use TangibleDDD\Core\Tests\Unit\Fixtures\JobFinished;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;

/**
 * AW1 (D13): orders a job, awaits its keyed answer, and derives the
 * answer's follow-up id from the event id of the fact that resumed it. The
 * post-await step fails `$failures` times and is retried by its policy; the
 * re-run must see the same event id.
 */
final class CauseReadingProcess extends LongProcess {

  public static int $failures = 0;

  public function __construct() {
    parent::__construct(null);
  }

  protected function order(): Result {
    Journal::note('order:' . var_export($this->resumed_by_event_id(), true));
    return new Result(
      commands: [new RecordingCommand('order-job', $this->step_ref('job'))],
      await: AwaitEvent::keyed(JobFinished::class, $this->step_ref('job')),
    );
  }

  #[RetryStep(attempts: 1)]
  protected function answer(mixed $payload, JobFinished $done): Result {
    Journal::note('answer:' . $this->resumed_by_event_id());
    if (self::$failures > 0) {
      self::$failures--;
      throw new \RuntimeException('follow-up failed');
    }
    return new Result();
  }

  protected function finish(): Result {
    Journal::note('finish:' . var_export($this->resumed_by_event_id(), true));
    return new Result();
  }
}
