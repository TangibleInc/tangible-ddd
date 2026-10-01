<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\Fixtures\Process\JobFinished;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\Fixtures\Process\ResumeCauseProcess;
use TangibleDDD\Conformance\ProcessHost;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Runtime\Scheduling\ICarriesFacts;
use TangibleDDD\Runtime\Scheduling\WakeRetryPolicy;

/**
 * AW2 (wave 5, CR-W5CC-7): a fact resume that cannot take the process lock
 * is parked as a ResumeRetry wakeup carrying the fact, the resume
 * subscriber succeeds, and the wakeup retries on the wake budget (never
 * dropped) until the lock is free. Needs ProcessHost and a wakeup
 * scheduler that implements ICarriesFacts (ProcessHost::wakeups(); the
 * runner of every worker uses that scheduler); otherwise skipped with
 * CR-W5CC-7.
 *
 * The process is ResumeCauseProcess, whose keyed await has no alarm, so an
 * answer can be held off past every budget without a timeout firing.
 */
abstract class ParkedAnswerScenarios extends ProcessScenarioCase {

  protected function setUp(): void {
    ResumeCauseProcess::$failures = 0;
    parent::setUp();
  }

  #[Group('lock.parked-answer')]
  #[TestDox('lock.parked-answer: the process lock is held elsewhere when the answer arrives; the answer is parked as one ResumeRetry carrying the fact, the delivery succeeds, the wake is retried while the lock is held and resumes the process with the answer\'s event id once it is free')]
  public function test_lock_parked_answer(): void {
    $processes = $this->parking();
    [$id, $eventId, $wrapped] = $this->answer_held_off($processes);
    $version = $this->row($id)->version;

    $outcome = $this->host->deliver(JobFinished::class, $wrapped);

    self::assertTrue($outcome->is_complete(), 'the resume subscriber succeeded: the fact is parked, not failed');
    self::assertSame([], $outcome->failed);
    $parked = $this->parked($id, $eventId);
    self::assertCount(1, $parked, 'one ResumeRetry intent carries the fact');
    $intent = $parked[0];
    self::assertSame(JobFinished::class, $intent->fact['class'] ?? null);
    self::assertSame((new JobFinished(ProcessJournal::widgets('order')[0]))->integration_payload(), $intent->fact['payload'] ?? null);
    self::assertSame('suspended', $intent->expected_status);
    self::assertSame(0, $intent->step_index, 'the suspended step the fact answers');
    self::assertSame($version, $this->row($id)->version, 'nothing was saved without the lock');
    self::assertSame(0, $processes->worker()->lock()->held_count());

    // A redelivery finds the answer taken; parking it again is the same intent.
    self::assertTrue($this->host->deliver(JobFinished::class, $wrapped)->is_complete());
    self::assertCount(1, $this->parked($id, $eventId));

    // While the lock is held, the due wake is retried, not dropped.
    $this->host->advance_clock(self::PAST_PARK_BACKOFF);
    $report = $processes->worker()->drain_once();
    self::assertContains($intent->key, $report->wakes_retried);
    self::assertSame('suspended', $this->row($id)->status);
    self::assertSame(0, ProcessJournal::runs(ResumeCauseProcess::entry('answer', 'w-1', $eventId)));

    // Free: the next due wake resumes the process with the parked answer.
    $processes->release_lock_elsewhere($id);
    $this->host->advance_clock(self::PAST_WAKE_BACKOFF);
    $report = $processes->worker()->drain_once();

    self::assertContains($intent->key, $report->wakes_completed);
    self::assertSame(1, ProcessJournal::runs(ResumeCauseProcess::entry('answer', 'w-1', $eventId)), 'resumed once, reading the parked fact\'s event id');
    self::assertGreaterThan($version, $this->row($id)->version);
    self::assertSame('suspended', $this->row($id)->status, 'the answered process now gathers');
    self::assertSame([], $this->parked($id, $eventId));
    self::assertSame(0, $processes->worker()->lock()->held_count());
  }

  #[Group('process.resume-contention-keeps-answer')]
  #[TestDox('process.resume-contention-keeps-answer: an answer held off by the process lock longer than the delivery budget and the wake budget is never dead-lettered, stays visible in the wakeup layer, and resumes the process once when the lock frees')]
  public function test_process_resume_contention_keeps_answer(): void {
    $processes = $this->parking();
    [$id, $eventId, $wrapped] = $this->answer_held_off($processes);

    $first = $this->host->deliver(JobFinished::class, $wrapped);
    self::assertCount(1, $first->delivered, 'the resume subscriber is the only subscriber and it succeeded');
    $resume = $first->delivered[0];
    self::assertSame(0, $this->host->ledger()->attempts($resume, $eventId), 'no delivery attempt was spent');
    [$intent] = $this->parked($id, $eventId);

    // The transport's own redeliveries, past the ledger budget: all skipped.
    for ($i = 0; $i <= IntegrationDelivery::DEFAULT_BUDGET; $i++) {
      self::assertContains($resume, $this->host->deliver(JobFinished::class, $wrapped)->skipped);
    }

    // Held off past the wake budget: retried at the cap every time, kept.
    for ($i = 0; $i < WakeRetryPolicy::BUDGET + 2; $i++) {
      $this->host->advance_clock(WakeRetryPolicy::CAP_SECONDS);
      self::assertContains($intent->key, $processes->worker()->drain_once()->wakes_retried, "retry $i");
    }
    self::assertSame('suspended', $this->row($id)->status, 'the process keeps waiting');
    self::assertFalse($this->host->ledger()->exhausted($resume, $eventId), 'the answer was never dead-lettered');
    self::assertCount(1, $this->parked($id, $eventId), 'the parked answer is kept');
    $wakeups = array_map(static fn (OperatorItem $i) => $i->key, $processes->operator_view()->list(Layer::Wakeup));
    self::assertContains($intent->key, $wakeups, 'visible to the operator in the wakeup layer');

    $processes->release_lock_elsewhere($id);
    $this->host->advance_clock(WakeRetryPolicy::CAP_SECONDS);
    self::assertContains($intent->key, $processes->worker()->drain_once()->wakes_completed);

    self::assertSame(1, ProcessJournal::runs(ResumeCauseProcess::entry('answer', 'w-1', $eventId)), 'resumed exactly once, with the answer');
    self::assertSame('suspended', $this->row($id)->status, 'the answered process now gathers');
    self::assertSame([], $this->parked($id, $eventId));
    self::assertFalse($this->host->ledger()->exhausted($resume, $eventId));
  }

  /**
   * Start a ResumeCauseProcess and hold its lock on another connection.
   *
   * @return array{0: int, 1: string, 2: array} process id, the answer's event id, the wrapped answer
   */
  private function answer_held_off(ProcessHost $processes): array {
    $processes->wire_processes([], [JobFinished::class, PartArrived::class]);
    $id = $this->start(new ResumeCauseProcess('w-1'));
    [$ref] = ProcessJournal::widgets('order');
    $processes->hold_lock_elsewhere($id);
    $eventId = Uuid::v4();
    return [$id, $eventId, self::wrap(new JobFinished($ref), $eventId)];
  }

  private function parking(): ProcessHost {
    $processes = $this->processes();
    if (!$processes->wakeups() instanceof ICarriesFacts) {
      $this->skip_for('CR-W5CC-7', 'the host wakeup scheduler does not carry facts (ICarriesFacts) yet');
    }
    return $processes;
  }
}
