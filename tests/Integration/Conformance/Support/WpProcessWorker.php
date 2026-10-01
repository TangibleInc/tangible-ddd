<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Conformance\ProcessWorker;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\Lock\IProcessLock;

/**
 * One wp worker of the conformance host. Worker 1 is the fixture's own
 * connection; worker n > 1 is another MySQL session (WpHostFixture builds
 * both). The closures carry the connection each operation runs on.
 */
final class WpProcessWorker implements ProcessWorker {

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
