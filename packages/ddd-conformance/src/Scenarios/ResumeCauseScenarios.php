<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\Fixtures\Process\JobFinished;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\ResumeCauseProcess;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Domain\Shared\Uuid;

/**
 * AW1 (D13; wave 5, CR-W5CC-8): the step a fact resumed reads that fact's
 * event id through LongProcess::resumed_by_event_id(), from the row the
 * resuming save wrote (the `steps` JSON every host already persists), so a
 * #[RetryStep] re-run on another worker reads the same id. Needs
 * ProcessHost.
 */
abstract class ResumeCauseScenarios extends ProcessScenarioCase {

  protected function setUp(): void {
    ResumeCauseProcess::$failures = 0;
    parent::setUp();
  }

  protected function tearDown(): void {
    parent::tearDown();
    ResumeCauseProcess::$failures = 0;
  }

  #[Group('process.resume-cause')]
  #[TestDox('process.resume-cause: the step a keyed fact resumed reads its event id, an AwaitAll step reads the id of the fact that completed the gather, other steps read none, and a retried step reads the same id from the row on another worker')]
  public function test_process_resume_cause(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [JobFinished::class, PartArrived::class]);

    // 1. A keyed answer: only the step it resumed sees its event id.
    $id = $this->start(new ResumeCauseProcess('w-1'));
    [$ref] = ProcessJournal::widgets('order');
    $answer = Uuid::v4();
    self::assertTrue($this->host->deliver(JobFinished::class, self::wrap(new JobFinished($ref), $answer))->is_complete());
    self::assertSame([
      ResumeCauseProcess::entry('order', 'w-1', null),
      ResumeCauseProcess::entry('answer', 'w-1', $answer),
    ], ProcessJournal::$steps, 'the first step has no cause; the answered step reads the answer\'s event id');
    self::assertSame('suspended', $this->row($id)->status, 'now gathering');

    // 2. An AwaitAll: the fact that completed the gather is the cause.
    $partA = Uuid::v4();
    $partB = Uuid::v4();
    self::assertTrue($this->host->deliver(PartArrived::class, self::wrap(new PartArrived('w-1', 'a'), $partA))->is_complete());
    self::assertCount(2, ProcessJournal::$steps, 'a partial gather runs no step');
    self::assertTrue($this->host->deliver(PartArrived::class, self::wrap(new PartArrived('w-1', 'b'), $partB))->is_complete());
    self::assertSame([
      ResumeCauseProcess::entry('assemble', 'w-1', $partB),
      ResumeCauseProcess::entry('finish', 'w-1', null),
    ], array_slice(ProcessJournal::$steps, 2), 'the completing fact\'s id; the step after it reads none');
    self::assertSame('completed', $this->row($id)->status);

    // 3. A retried step reads the cause from the row, on another worker.
    ResumeCauseProcess::$failures = 1;
    $retried = $this->start(new ResumeCauseProcess('w-2'));
    [, $ref2] = ProcessJournal::widgets('order');
    $answer2 = Uuid::v4();
    self::assertTrue($this->host->deliver(JobFinished::class, self::wrap(new JobFinished($ref2), $answer2))->is_complete());
    self::assertSame('scheduled', $this->row($retried)->status, 'the failed step is scheduled for its retry');

    $processes->worker(2)->drain_once();

    $entry = ResumeCauseProcess::entry('answer', 'w-2', $answer2);
    self::assertSame(2, ProcessJournal::runs($entry), 'the re-run read the same event id');
    self::assertSame('suspended', $this->row($retried)->status, 'the retry succeeded and gathers');
  }
}
