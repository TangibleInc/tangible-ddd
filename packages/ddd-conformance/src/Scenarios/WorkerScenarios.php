<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\WidgetCreated;
use TangibleDDD\Runtime\Lock\LockKey;
use TangibleDDD\Runtime\RuntimeLeakDetected;

/** Long-running worker hygiene (register section 4, 3.9 RuntimeReset). */
abstract class WorkerScenarios extends ConformanceTestCase {

  #[Group('worker.no-leak')]
  #[TestDox('worker.no-leak: after a failing message that leaks, the next message sees no scope, an empty unit of work, no resume argument and no held lock')]
  public function test_worker_no_leak(): void {
    $lock = $this->host->processLock();
    $key = new LockKey('ddd_conformance', '', 42);
    $seen = null;

    $failing = function () use ($lock, $key): void {
      Correlation::current();                                          // mints an ambient story and leaves it open
      $this->host->events()->record(new WidgetCreated('leaked'));      // unit-of-work state
      $lock->acquire($key, 1.0);                                       // never released
      throw new \RuntimeException('first message fails mid-flight');
    };
    $observing = function () use ($lock, $key, &$seen): void {
      $queued = $this->host->events()->drain();
      $seen = [
        'correlation' => Correlation::peek(),
        'queued' => $queued,
        'published' => $this->host->events()->published(),
        'held' => $lock->heldCount(),
        'runner' => $this->host->runnerTransients(),
      ];
      $lock->release($lock->acquire($key, 1.0));                       // the leaked lock is really gone
    };

    $run = $this->host->runWorker([$failing, $observing]);

    self::assertInstanceOf(\RuntimeException::class, $run->errors[0]);
    self::assertNull($run->errors[1], 'the second message ran cleanly');
    self::assertInstanceOf(RuntimeLeakDetected::class, $run->leaks[0], 'the leak fails loudly at the boundary');
    self::assertNull($run->leaks[1], 'and is not sticky');

    self::assertNotNull($seen);
    self::assertNull($seen['correlation'], 'Correlation::peek() === null');
    self::assertSame([], $seen['queued'], 'empty unit of work');
    self::assertSame([], $seen['published']);
    self::assertSame(0, $seen['held'], 'zero held locks');
    if ($seen['runner'] !== null) {
      self::assertNull($seen['runner']['resume_argument'] ?? null, 'null resume argument');
    }
  }
}
