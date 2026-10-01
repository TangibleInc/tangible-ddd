<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Conformance\ProcessWorker;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\Lock\IProcessLock;

/**
 * One sf worker (CR-W3CP-1): its own DBAL connection and advisory-lock
 * session, its own core ProcessRunner and Messenger delivery bus, over the
 * fixture's schema. SfHostFixture builds them; the closures run the host's
 * real machinery for this worker.
 */
final class SfProcessWorker implements ProcessWorker {

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
