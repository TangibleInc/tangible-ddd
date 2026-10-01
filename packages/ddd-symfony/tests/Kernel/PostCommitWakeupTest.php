<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel;

use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Symfony\Runtime\Wakeup\PostgresListenWaiter;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RegisterWidgetCommand;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;

/**
 * D14 post-commit wakeup (scenario wakeup.post-commit, the NOTIFY half), end
 * to end: a real `ddd:relay` loop in another php process, idle in LISTEN
 * with a 3 s poll interval. The outbox append's NOTIFY is delivered at
 * COMMIT, so the worker relays the fact well under a second; a rolled-back
 * command sends nothing. A wakeup intent wakes the worker the same way.
 * PostCommitPollFallbackTest is the half without NOTIFY.
 */
final class PostCommitWakeupTest extends RelayWorkerTestBase {

  public function test_a_committed_fact_is_relayed_in_under_a_second_by_the_notify(): void {
    $this->startWorker();
    $this->waitUntilWorkerListens();

    $eventId = $this->commitFact('w-notify');
    $seconds = $this->secondsUntilAccepted($eventId, 2 * self::POLL_SECONDS);

    self::assertNotNull($seconds, 'relayed at all: ' . $this->workerErrors());
    self::assertLessThan(1.0, $seconds, sprintf('relayed after %.3f s; the poll interval is %d s', $seconds, self::POLL_SECONDS));
    self::assertSame(1, $this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_facts'"));
  }

  public function test_a_rolled_back_command_sends_no_notification_and_relays_nothing(): void {
    $listener = PostgresDatabase::connect();
    try {
      $waiter = new PostgresListenWaiter($listener, 'sfk');
      $waiter->listen();

      try {
        (new RegisterWidgetCommand('w-rollback', 'x', failAfterWrite: true))->send();
        self::fail('expected the handler exception');
      } catch (\DomainException) {
      }

      self::assertFalse($waiter->wait(1.0), 'no NOTIFY escaped the rolled-back transaction');
      self::assertSame(0, $this->countRows('SELECT count(*) FROM ddd_outbox'));

      // The same listener does see a committed one (the probe works).
      $this->commitFact('w-after');
      self::assertTrue($waiter->wait(1.0));
    } finally {
      $listener->close();
    }
  }

  public function test_a_committed_wakeup_intent_wakes_the_worker_too(): void {
    $this->startWorker();
    $this->waitUntilWorkerListens();

    $start = microtime(true);
    $c = self::getContainer();
    $c->get('tangible_ddd.transaction_boundary')->run(
      fn () => $c->get(IWakeupScheduler::class)->schedule(WakeupIntent::continuation('sfk', 4242, 0, new \DateTimeImmutable()))
    );

    $projected = null;
    while (microtime(true) - $start < 2 * self::POLL_SECONDS) {
      if ($this->countRows("SELECT count(*) FROM messenger_messages WHERE queue_name = 'ddd_wakeups'") === 1) {
        $projected = microtime(true) - $start;
        break;
      }
      usleep(20_000);
    }
    self::assertNotNull($projected, 'projected at all: ' . $this->workerErrors());
    self::assertLessThan(1.0, $projected, sprintf('projected after %.3f s; the poll interval is %d s', $projected, self::POLL_SECONDS));
  }
}
