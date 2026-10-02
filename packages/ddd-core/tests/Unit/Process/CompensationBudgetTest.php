<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Process;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Core\Tests\Unit\Fixtures\AcmeConfig;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\Journal;
use TangibleDDD\Core\Tests\Unit\Fixtures\Process\RefundingProcess;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;
use TangibleDDD\Testing\InMemoryProcessLock;
use TangibleDDD\Testing\InMemoryProcessStore;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryWakeupScheduler;

require_once dirname(__DIR__) . '/Fixtures/Process/CoreProcesses.php';

/**
 * A process that hits its resource budget right after its LAST compensation
 * step must still end `failed`. undo_index -1 means both "compensation done"
 * and "not compensating", so a continuation scheduled at that point used to
 * wake into execute_forward(): the failed step re-ran and the process could
 * end `completed` (reported by the TXP pipeline, 2026-10-02).
 */
final class CompensationBudgetTest extends TestCase {

  private FrozenClock $clock;
  private InMemoryTransactionBoundary $boundary;
  private InMemoryProcessStore $store;
  private InMemoryWakeupScheduler $wakeups;
  private ProcessRunner $runner;

  protected function setUp(): void {
    HostDefaults::reset_for_tests();
    HostDefaults::provide(LoggerInterface::class, new RecordingLogger());
    Correlation::reset();
    Journal::reset();
    RecordingCommand::$sent = [];
    RecordingCommand::$onSend = null;

    $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-02 12:00:00', new \DateTimeZone('UTC')));
    $this->boundary = new InMemoryTransactionBoundary();
    $this->store = new InMemoryProcessStore($this->clock);
    $this->wakeups = new InMemoryWakeupScheduler($this->boundary);
    $this->store->attach_intents($this->wakeups);
    $this->boundary->enlist($this->store);
    $this->boundary->enlist($this->wakeups);
    $this->runner = new ProcessRunner(
      new AcmeConfig(), null, new InMemoryProcessLock(), $this->store, $this->wakeups, new SubscriptionRegistry(), $this->boundary, $this->clock,
    );
  }

  protected function tearDown(): void {
    Correlation::reset();
    HostDefaults::reset_for_tests();
  }

  /** Every resources_exceeded() check trips: a zero-second time budget. */
  private function exhaust_budget_on_every_check(): void {
    (new \ReflectionProperty($this->runner, 'max_execution_seconds'))->setValue($this->runner, 0);
  }

  /** Claim and run every due intent until none is left (bounded). */
  private function drain(int $rounds = 10): void {
    for ($i = 0; $i < $rounds; $i++) {
      $claimed = $this->wakeups->claim_due($this->clock->now(), 50, 60);
      if ($claimed === []) {
        return;
      }
      foreach ($claimed as $c) {
        $this->runner->wake($c->intent);
        $this->boundary->run(fn () => $this->wakeups->complete($c));
      }
    }
  }

  public function test_a_budget_hit_after_the_last_compensation_still_fails_the_process(): void {
    $this->exhaust_budget_on_every_check();
    $p = new RefundingProcess();

    $this->runner->start($p);
    $this->drain();

    self::assertSame('failed', $this->store->status_of($p->get_id()));
    self::assertSame(['charge', 'deliver', 'refund'], Journal::$steps, 'each step and its compensation ran exactly once');
    self::assertSame(['charge', 'refund'], array_map(static fn (RecordingCommand $c) => $c->label, RecordingCommand::$sent));
    self::assertSame([], $this->wakeups->pending(), 'nothing is left scheduled');
  }

  public function test_the_budget_still_yields_between_compensations_while_undo_steps_remain(): void {
    $p = new TwoRefundsProcess();
    $this->runner->start($p);   // normal budget: book, charge, then ship fails and both undo steps run
    self::assertSame('failed', $this->store->status_of($p->get_id()));
    self::assertSame(['book', 'charge', 'ship', 'refund', 'unbook'], Journal::$steps);

    Journal::reset();
    $this->exhaust_budget_on_every_check();
    $q = new TwoRefundsProcess();
    $this->runner->start($q);

    // The first wake runs only up to a budget check, so the governor yields.
    self::assertNotSame('failed', $this->store->status_of($q->get_id()));
    $this->drain();

    self::assertSame('failed', $this->store->status_of($q->get_id()));
    self::assertSame(['book', 'charge', 'ship', 'refund', 'unbook'], Journal::$steps, 'each ran once across the yields');
  }

  public function test_a_continuation_queued_after_the_last_compensation_finishes_the_process(): void {
    // A continuation that older code scheduled as 'undo-end' wakes a process
    // whose compensation already ran to completion but which is not failed yet.
    $p = new RefundingProcess();
    $this->runner->start($p);
    self::assertSame('failed', $this->store->status_of($p->get_id()), 'precondition: the normal path fails it');

    $copy = $this->store->find($p->get_id());
    $steps = (new \ReflectionMethod($copy, 'steps'))->invoke($copy);
    $copy->advance(status: 'scheduled', payload: $copy->payload());
    self::assertFalse($copy->is_compensating());
    self::assertNotNull($copy->failure_message());
    $this->boundary->run(fn () => $this->store->save($copy, (int) $this->store->version_of($copy->get_id())));
    Journal::reset();
    RecordingCommand::$sent = [];

    $this->boundary->run(fn () => $this->wakeups->schedule(
      WakeupIntent::continuation((new AcmeConfig())->prefix(), $p->get_id(), $steps->step_index, $this->clock->now()),
    ));
    $this->drain();

    self::assertSame('failed', $this->store->status_of($p->get_id()));
    self::assertSame([], Journal::$steps, 'no forward step re-ran and no compensation repeated');
    self::assertSame([], RecordingCommand::$sent);
  }
}

/** Two compensated steps before a failing third: the undo cascade has two hops. */
final class TwoRefundsProcess extends \TangibleDDD\Application\Process\LongProcess {

  public function __construct() {
    parent::__construct(null);
  }

  protected function book(): \TangibleDDD\Application\Process\Result {
    Journal::note('book');
    return new \TangibleDDD\Application\Process\Result();
  }

  protected function charge(): \TangibleDDD\Application\Process\Result {
    Journal::note('charge');
    return new \TangibleDDD\Application\Process\Result();
  }

  protected function ship(): \TangibleDDD\Application\Process\Result {
    Journal::note('ship');
    throw new \RuntimeException('no courier');
  }

  #[\TangibleDDD\Application\Process\Compensates('charge')]
  protected function refund(\Throwable $cause, mixed $checkpoint): \TangibleDDD\Application\Process\Result {
    Journal::note('refund');
    return new \TangibleDDD\Application\Process\Result();
  }

  #[\TangibleDDD\Application\Process\Compensates('book')]
  protected function unbook(\Throwable $cause, mixed $checkpoint): \TangibleDDD\Application\Process\Result {
    Journal::note('unbook');
    return new \TangibleDDD\Application\Process\Result();
  }
}
