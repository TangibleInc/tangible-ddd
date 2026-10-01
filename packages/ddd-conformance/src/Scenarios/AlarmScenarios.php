<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Scenarios;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\Fixtures\Process\LongAlarmProcess;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Conformance\ProcessScenarioCase;
use TangibleDDD\Runtime\Scheduling\WakeKind;

/**
 * D7 long alarms (register section 4 `process.alarm-long`, 3.11; TXP
 * process-kernel). Needs ProcessHost. The restarted worker is worker(2): a
 * runner built after the alarm was armed, over the same rows, that never
 * saw the process in memory.
 */
abstract class AlarmScenarios extends ProcessScenarioCase {

  #[Group('process.alarm-long')]
  #[TestDox('process.alarm-long: a 25 h alarm survives a worker restart and fires once at its absolute due time, with no second delay')]
  public function test_process_alarm_long(): void {
    $processes = $this->processes();
    $armedAt = $this->host->clock()->now();
    $id = $this->start(new LongAlarmProcess('w-1'));

    self::assertSame('suspended', $this->row($id)->status);
    $alarms = $this->intents($id, WakeKind::Timeout);
    self::assertCount(1, $alarms, 'one durable alarm intent');
    self::assertSame(
      $armedAt->modify('+' . LongAlarmProcess::ALARM_SECONDS . ' seconds')->getTimestamp(),
      $alarms[0]->due_at->getTimestamp(),
      'due exactly 25 h after suspension (absolute UTC instant)',
    );
    $deadline = $processes->process_store()->find($id)?->await_deadline();
    self::assertNotNull($deadline, 'the instant is stored with the process');
    self::assertSame($alarms[0]->due_at->getTimestamp(), $deadline->getTimestamp());

    // The worker restarts: worker 2 drains from here on.
    $restarted = $processes->worker(2);
    $this->host->advance_clock(LongAlarmProcess::ALARM_SECONDS - 1);
    $restarted->drain_once();
    self::assertSame(0, ProcessJournal::runs('fire'), 'not due 1 s before the instant');
    self::assertSame('suspended', $this->row($id)->status);
    self::assertCount(1, $this->intents($id, WakeKind::Timeout), 'the intent is not re-delayed');

    $this->host->advance_clock(1);
    $restarted->drain_once();
    self::assertSame(1, ProcessJournal::runs('fire'), 'fires at the instant');
    self::assertSame('completed', $this->row($id)->status);
    $version = $this->row($id)->version;

    $this->host->advance_clock(48 * 3600);
    $restarted->drain_once();
    $processes->worker()->drain_once();
    $restarted->runner()->wake($alarms[0]); // a surviving duplicate of the wake

    self::assertSame(['arm', 'fire'], ProcessJournal::$steps, 'fired once, no re-arm');
    self::assertSame(['fired'], ProcessJournal::labels());
    self::assertSame($version, $this->row($id)->version, 'the duplicate wrote nothing');
    self::assertSame([], $this->intents($id));
  }
}
