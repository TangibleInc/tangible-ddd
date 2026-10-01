<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Conformance\Fixtures\Process\ProcessJournal;
use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * Base of the wave-3 process and lock scenario cases. They need the
 * optional ProcessHost seam (CR-W3CP-1); without it every scenario is
 * skipped with that request id.
 */
abstract class ProcessScenarioCase extends ConformanceTestCase {

  /** Past the wake backoff cap (WakeRetryPolicy::CAP_SECONDS = 300). */
  protected const PAST_WAKE_BACKOFF = 301;

  /** Longer than any host's relay lease and relay backoff cap (as RelayScenarios). */
  protected const PAST_ANY_LEASE = 3601;

  protected function setUp(): void {
    ProcessJournal::reset();
    parent::setUp();
  }

  protected function tearDown(): void {
    parent::tearDown();
    ProcessJournal::reset();
  }

  protected function processes(): ProcessHost {
    if (!$this->host instanceof ProcessHost) {
      $this->skip_for('CR-W3CP-1', 'the host fixture does not implement ProcessHost yet');
    }
    return $this->host;
  }

  /**
   * Start $process on worker $worker, start-mode neutral: when the host
   * defers the first step (sf, StartMode::Deferred) one drain runs it, so
   * the scenario continues from the same state on every host.
   */
  protected function start(LongProcess $process, int $worker = 1): int {
    $this->processes()->worker($worker)->runner()->start($process);
    $id = (int) $process->get_id();
    self::assertGreaterThan(0, $id, 'start() persisted the process');

    $row = $this->row($id);
    if ($row->status === 'scheduled' && $row->step_index === 0) {
      $this->processes()->worker($worker)->drain_once();
    }
    return $id;
  }

  protected function row(int $id): ProcessRow {
    $row = $this->processes()->process_row($id);
    self::assertNotNull($row, "process #$id exists");
    return $row;
  }

  /** @return list<WakeupIntent> live intents of $kind for process $id */
  protected function intents(int $id, ?WakeKind $kind = null): array {
    return array_values(array_filter(
      $this->processes()->live_intents(),
      static fn (WakeupIntent $i) => $i->process_id === $id && ($kind === null || $i->kind === $kind),
    ));
  }

  /** @return list<string> */
  protected function intent_keys(int $id, ?WakeKind $kind = null): array {
    return array_map(static fn (WakeupIntent $i) => $i->key, $this->intents($id, $kind));
  }
}
