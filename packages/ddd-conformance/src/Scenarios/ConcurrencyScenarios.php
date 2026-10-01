<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Conformance\Fixtures\Process\GatherPartsProcess;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Domain\Shared\Uuid;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\Lock\LockNotAcquired;

/**
 * Two workers on two connections against one database (register section 4;
 * `-` on mem, which runs them only under the `simulated` group). wp marks
 * `lock.namespace` `-` while it also takes the legacy `ddd_process_<id>`
 * name (register 3.7) and overrides it with a skip.
 */
abstract class ConcurrencyScenarios extends ProcessScenarioCase {

  #[Group('process.await-all-concurrent')]
  #[TestDox('process.await-all-concurrent: both keys of a 2-key AwaitAll delivered concurrently; the process resumes once with both')]
  public function test_process_await_all_concurrent(): void {
    $processes = $this->processes();
    $processes->wire_processes([], [PartArrived::class]);
    $id = $this->start(new GatherPartsProcess('w-1', ['a', 'b'], AwaitAll::TIMEOUT_FAIL));
    $a = self::wrap(new PartArrived('w-1', 'a'), Uuid::v4());
    $b = self::wrap(new PartArrived('w-1', 'b'), Uuid::v4());

    // Worker 1 has read the row for key a and is about to lock it; worker 2
    // delivers key b at that moment.
    $second = null;
    $processes->before_next_lock(function () use ($processes, $b, &$second): void {
      $second = $processes->worker(2)->deliver(PartArrived::class, $b);
    });
    $first = $processes->worker(1)->deliver(PartArrived::class, $a);
    self::assertInstanceOf(DeliveryOutcome::class, $second, 'the two deliveries overlapped');

    // Whichever lost the lock is retried by its delivery runner (register 3.7).
    foreach ([[$first, $a], [$second, $b]] as [$outcome, $wrapped]) {
      if ($outcome->needs_retry()) {
        self::assertTrue($processes->worker(1)->deliver(PartArrived::class, $wrapped)->is_complete());
      }
    }
    // On a scheduler that carries facts (CR-W5CC-7) the loser was parked
    // instead: one drain past the first wake backoff resumes it (well before
    // the gather's alarm). Elsewhere the drain finds nothing due.
    $this->host->advance_clock(self::PAST_PARK_BACKOFF);
    $processes->worker(1)->drain_once();

    self::assertSame(1, ProcessJournal::runs('assemble:w-1:a,w-1:b'), 'resumed once, with both keys');
    self::assertSame('completed', $this->row($id)->status);
    self::assertSame(0, ProcessJournal::runs('undo_prepare'));
  }

  #[Group('lock.namespace')]
  #[TestDox('lock.namespace: two consumers lock the same process id from two connections without blocking each other')]
  public function test_lock_namespace(): void {
    $processes = $this->processes();
    $one = $processes->worker(1)->lock();
    $two = $processes->worker(2)->lock();
    $consumerA = new LockKey($processes->consumer_prefix() . '_a', '', 7);
    $consumerB = new LockKey($processes->consumer_prefix() . '_b', '', 7);

    $held = $one->acquire($consumerA, 1.0);

    $other = $two->acquire($consumerB, 1.0);
    self::assertSame(1, $two->held_count(), 'consumer B took process 7 while consumer A holds its process 7');
    $two->release($other);

    // Control: connection 2 really is another session.
    self::assertInstanceOf(LockNotAcquired::class, self::thrown(static fn () => $two->acquire($consumerA, 0.2)));

    $one->release($held);
    $two->release($two->acquire($consumerA, 0.2));
    self::assertSame(0, $one->held_count());
    self::assertSame(0, $two->held_count());
  }
}
