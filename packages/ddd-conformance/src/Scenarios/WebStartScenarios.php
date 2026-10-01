<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\Fixtures\CreateWidget;
use TangibleDDD\Conformance\Fixtures\Process\MakeWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Conformance\WebRequests;
use TangibleDDD\Runtime\Scheduling\WakeKind;

/**
 * `process.start-from-web` (sf only; register 3.8 "sf start", 5.2): a web
 * request on a pooled connection persists the process and its Continue
 * intent in the caller's transaction and takes no advisory lock; the first
 * step runs in a worker; the in-band opt-in on a pooled DSN is refused at
 * boot. Needs WebRequests (CR-W3CP-5) on top of ProcessHost.
 */
abstract class WebStartScenarios extends ProcessScenarioCase {

  #[Group('process.start-from-web')]
  #[TestDox('process.start-from-web: start() in a web request commits the row and the Continue intent with the caller\'s transaction, takes no lock, and the first step runs in the worker; the in-band opt-in on a pooled DSN is refused at boot')]
  public function test_process_start_from_web(): void {
    $web = $this->web();
    $processes = $this->processes();
    $runner = $processes->worker()->processRunner();
    $started = null;
    $failAfterStart = false;
    $bus = $this->host->commandBus([CreateWidget::class => function (CreateWidget $c) use ($runner, &$started, &$failAfterStart): void {
      $this->host->scenarioRows()->insert($c->widget_id, 'created');
      $started = new MakeWidgetProcess($c->widget_id);
      $runner->start($started);
      if ($failAfterStart) {
        throw new \DomainException('the command fails after the start');
      }
    }]);
    $locks = $processes->processLockAcquisitions();

    $web->inWebRequest(static fn () => $bus->handle(new CreateWidget('w-1')));

    $id = (int) $started?->get_id();
    self::assertSame($locks, $processes->processLockAcquisitions(), 'no process lock is taken in the request');
    self::assertTrue($this->host->scenarioRows()->has('w-1'));
    self::assertSame('scheduled', $this->row($id)->status, 'persisted, first step not run');
    self::assertSame(["continue:$id:0"], $this->intentKeys($id, WakeKind::Continue), 'with its Continue intent');
    self::assertSame([], ProcessJournal::$steps);

    // The row and the intent commit WITH the caller's transaction.
    $count = count($processes->processIds());
    $intents = count($processes->pendingWakeups());
    $failAfterStart = true;
    self::assertInstanceOf(\DomainException::class, self::catchThrowable(
      static fn () => $web->inWebRequest(static fn () => $bus->handle(new CreateWidget('w-2'))),
    ));
    self::assertCount($count, $processes->processIds(), 'rolled back with the command: no process row');
    self::assertCount($intents, $processes->pendingWakeups(), 'and no intent');
    self::assertFalse($this->host->scenarioRows()->has('w-2'));

    // The first step runs in the worker.
    $processes->worker()->drainOnce();
    self::assertSame(['make', 'finish'], ProcessJournal::$steps);
    self::assertSame('completed', $this->row($id)->status);

    // In-band starts need the direct connection: refused at boot on a pooled DSN.
    self::assertInstanceOf(\Throwable::class, $web->bootInBandStartOnPooledDsn(), 'the in-band opt-in on a pooled DSN does not boot');
  }

  protected function web(): WebRequests {
    $this->processes();
    if (!$this->host instanceof WebRequests) {
      $this->skipForChangeRequest('CR-W3CP-5', 'the host fixture does not implement WebRequests yet');
    }
    return $this->host;
  }
}
