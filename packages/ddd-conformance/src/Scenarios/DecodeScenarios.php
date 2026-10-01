<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Application\Process\AwaitAll;
use TangibleDDD\Conformance\Fixtures\Process\GatherPartsProcess;
use TangibleDDD\Conformance\Fixtures\Process\HopWidgetProcess;
use TangibleDDD\Conformance\Fixtures\Process\PartArrived;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\ProcessDecodeFaults;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Domain\Shared\Uuid;

/**
 * Undecodable process rows (register section 4 `decode.unknown-class`, 3.8
 * "Quarantine", R5). Needs ProcessHost and ProcessDecodeFaults (CR-W4C4-3).
 */
abstract class DecodeScenarios extends ProcessScenarioCase {

  /** A process class that does not exist (deleted or renamed by a deploy). */
  protected const MISSING_CLASS = 'TangibleDDD\\Conformance\\Fixtures\\Process\\DeletedWidgetProcess';

  #[Group('decode.unknown-class')]
  #[TestDox('decode.unknown-class: a row whose process class no longer exists is quarantined (status failed, quarantine_reason set) when a worker meets it; the worker continues with the other processes')]
  public function test_decode_unknown_class(): void {
    $faults = $this->decode_faults();
    $processes = $this->processes();
    self::assertFalse(class_exists(self::MISSING_CLASS));

    // 1. A continuation of the undecodable row, in the same pass as a healthy one.
    $bad = $this->start(new HopWidgetProcess('w-1'));
    $good = $this->start(new HopWidgetProcess('w-2'));
    self::assertSame(['first', 'first'], ProcessJournal::$steps);
    $faults->forget_class($bad, self::MISSING_CLASS);

    $report = $processes->worker()->drain_once();

    self::assertSame([], $report->errors, 'the drain pass did not fail');
    self::assertSame('failed', $faults->stored_status($bad), 'quarantined: status failed, no new status value (R5)');
    self::assertNotEmpty($faults->quarantine_reason($bad), 'quarantine_reason is set');
    self::assertSame('completed', $this->row($good)->status, 'the worker continued with the next process');
    self::assertSame(1, ProcessJournal::runs('second'), 'only the healthy process ran its step');

    // Later passes never run a step of the quarantined row nor fail the worker.
    for ($pass = 0; $pass < 3; $pass++) {
      $this->host->advance_clock(self::PAST_WAKE_BACKOFF);
      $processes->worker()->drain_once();
    }
    self::assertSame('failed', $faults->stored_status($bad));
    self::assertSame(1, ProcessJournal::runs('second'));

    // 2. A suspended row: facts for other processes still resume them, a
    //    fact for it fails nothing, and its alarm quarantines it.
    $processes->wire_processes([], [PartArrived::class]);
    $suspended = $this->start(new GatherPartsProcess('w-3', ['a'], AwaitAll::TIMEOUT_FAIL));
    $healthy = $this->start(new GatherPartsProcess('w-4', ['a'], AwaitAll::TIMEOUT_FAIL));
    $faults->forget_class($suspended, self::MISSING_CLASS);

    $outcome = $this->host->deliver(PartArrived::class, self::wrap(new PartArrived('w-3', 'a'), Uuid::v4()));
    self::assertTrue($outcome->is_complete(), 'a fact meeting the undecodable row does not fail the delivery');
    $outcome = $this->host->deliver(PartArrived::class, self::wrap(new PartArrived('w-4', 'a'), Uuid::v4()));
    self::assertTrue($outcome->is_complete());
    self::assertSame('completed', $this->row($healthy)->status);
    self::assertSame(0, ProcessJournal::runs('assemble:w-3:a'));

    $this->host->advance_clock(GatherPartsProcess::TIMEOUT_SECONDS + 1);
    $processes->worker()->drain_once();
    self::assertSame('failed', $faults->stored_status($suspended), 'the alarm wake quarantines it');
    self::assertNotEmpty($faults->quarantine_reason($suspended));
    self::assertSame(0, ProcessJournal::runs('undo_prepare'), 'a quarantined row is not compensated: its code is gone');
  }

  protected function decode_faults(): ProcessDecodeFaults {
    $this->processes();
    if (!$this->host instanceof ProcessDecodeFaults) {
      $this->skip_for('CR-W4C4-3', 'the host fixture does not implement ProcessDecodeFaults yet');
    }
    return $this->host;
  }
}
