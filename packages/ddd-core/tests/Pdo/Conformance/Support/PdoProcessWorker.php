<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Support;

use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Conformance\ProcessWorker;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Drain;
use TangibleDDD\Runtime\DrainReport;
use TangibleDDD\Runtime\Lock\IProcessLock;

/**
 * One pdo worker: its own MySQL session (connection), and on it the pdo
 * adapter set, a ProcessRunner and a core Drain, composed as
 * DurableRuntime::compose() composes them (PdoHostFixture builds it).
 */
final class PdoProcessWorker implements ProcessWorker {

  public function __construct(
    private readonly ProcessRunner $runner,
    private readonly IProcessLock $lock,
    private readonly IntegrationDelivery $delivery,
    private readonly Drain $drain,
  ) {}

  public function processRunner(): ProcessRunner {
    return $this->runner;
  }

  public function processLock(): IProcessLock {
    return $this->lock;
  }

  public function deliverFact(string $eventClass, array $wrapped): DeliveryOutcome {
    return $this->delivery->deliver($eventClass, $wrapped);
  }

  public function drainOnce(int $maxItems = 200): DrainReport {
    return $this->drain->runOnce($maxItems);
  }
}
