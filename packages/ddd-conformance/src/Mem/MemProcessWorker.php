<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Mem;

use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Conformance\ProcessWorker;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\Lock\IProcessLock;

/**
 * One mem worker: a core ProcessRunner over the fixture's shared stores
 * (the "database"), with its own ReentrantProcessLock (its "lock session")
 * and its own subscription registry. The MemHostFixture builds them.
 */
final class MemProcessWorker implements ProcessWorker {

  /**
   * @param \Closure(string, array): DeliveryOutcome $deliver
   * @param \Closure(int): DrainReport $drain
   */
  public function __construct(
    private readonly ProcessRunner $runner,
    private readonly IProcessLock $lock,
    private readonly \Closure $deliver,
    private readonly \Closure $drain,
  ) {}

  public function processRunner(): ProcessRunner {
    return $this->runner;
  }

  public function processLock(): IProcessLock {
    return $this->lock;
  }

  public function deliverFact(string $eventClass, array $wrapped): DeliveryOutcome {
    return ($this->deliver)($eventClass, $wrapped);
  }

  public function drainOnce(int $maxItems = 200): DrainReport {
    return ($this->drain)($maxItems);
  }
}
