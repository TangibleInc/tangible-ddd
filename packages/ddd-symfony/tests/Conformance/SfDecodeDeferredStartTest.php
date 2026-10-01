<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Conformance\Fixtures\Process\GatherPartsProcess;
use TangibleDDD\Conformance\Fixtures\Process\HopWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Domain\Shared\Uuid;

/**
 * `decode.unknown-class` under the bundle default StartMode::Deferred.
 *
 * The shared scenario (SfDecodeScenariosTest, run in-band) starts its two
 * hop processes one after the other and expects ['first', 'first']; with a
 * deferred start the second start's drain also runs the first process's
 * due `second` step, so the scenario's opening assertion cannot hold on a
 * deferred host (reported to conformance in
 * wave4-sf-conf4-change-requests.md). This sf-only test makes the same
 * claims with both processes started before the class is forgotten: the
 * drain quarantines the undecodable row (status `failed`, quarantine_reason)
 * and continues with the healthy one, later passes leave it alone, a fact
 * for a suspended undecodable row fails no delivery, and its alarm wake
 * quarantines it without compensation.
 */
#[Group('sf')]
#[Group('conformance')]
final class SfDecodeDeferredStartTest extends ProcessScenarioCase {

  private const MISSING_CLASS = 'TangibleDDD\\Conformance\\Fixtures\\Process\\DeletedWidgetProcess';

  protected function createFixture(): HostFixture {
    return new SfHostFixture();
  }

  public function test_an_undecodable_row_is_quarantined_and_the_worker_continues_under_deferred_start(): void {
    $host = $this->host;
    assert($host instanceof SfHostFixture);
    $runner = $host->worker()->processRunner();

    $bad = new HopWidgetProcess('w-1');
    $good = new HopWidgetProcess('w-2');
    $runner->start($bad);
    $runner->start($good);
    $bad = (int) $bad->get_id();
    $good = (int) $good->get_id();
    $host->worker()->drainOnce(); // both first steps (deferred), each schedules its #[Async] second step
    self::assertSame(['first', 'first'], ProcessJournal::$steps);
    self::assertSame('scheduled', $this->row($bad)->status);

    $host->forgetProcessClass($bad, self::MISSING_CLASS);
    $report = $host->worker()->drainOnce();

    self::assertSame([], $report->errors, 'the drain pass did not fail');
    self::assertSame('failed', $host->storedProcessStatus($bad));
    self::assertNotEmpty($host->quarantineReason($bad));
    self::assertStringContainsString(self::MISSING_CLASS, (string) $host->quarantineReason($bad));
    self::assertSame('completed', $this->row($good)->status);
    self::assertSame(1, ProcessJournal::runs('second'));

    for ($pass = 0; $pass < 3; $pass++) {
      $host->advanceClock(self::PAST_WAKE_BACKOFF);
      self::assertSame([], $host->worker()->drainOnce()->errors);
    }
    self::assertSame('failed', $host->storedProcessStatus($bad));
    self::assertSame(1, ProcessJournal::runs('second'));

    $host->wireProcesses([], [PartArrived::class]);
    $suspended = $this->start(new GatherPartsProcess('w-3', ['a'], AwaitAll::TIMEOUT_FAIL));
    $healthy = $this->start(new GatherPartsProcess('w-4', ['a'], AwaitAll::TIMEOUT_FAIL));
    $host->forgetProcessClass($suspended, self::MISSING_CLASS);

    self::assertTrue($host->deliver(PartArrived::class, self::wrap(new PartArrived('w-3', 'a'), Uuid::v4()))->isComplete());
    self::assertTrue($host->deliver(PartArrived::class, self::wrap(new PartArrived('w-4', 'a'), Uuid::v4()))->isComplete());
    self::assertSame('completed', $this->row($healthy)->status);

    $host->advanceClock(GatherPartsProcess::TIMEOUT_SECONDS + 1);
    $host->worker()->drainOnce();
    self::assertSame('failed', $host->storedProcessStatus($suspended));
    self::assertNotEmpty($host->quarantineReason($suspended));
    self::assertSame(0, ProcessJournal::runs('undo_prepare'));
  }
}
