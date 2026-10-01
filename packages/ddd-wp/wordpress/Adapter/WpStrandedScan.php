<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\StrandedProcess;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\SystemClock;

/**
 * The stranded scan of a wp relay tick (register 5.3 step 5, wp form).
 *
 * For every `IProcessStore::findStranded()` row:
 * - `scheduled`: if Action Scheduler already holds a pending or running
 *   `{prefix}_process_continue` action with the legacy args
 *   ['process_id' => id] (queued by a 0.6 copy, or by N before a
 *   rollback), NO intent is minted: the legacy args cannot carry the step
 *   index, so a second continuation would re-run a step after a rollback.
 *   Otherwise a fresh Continue intent (continuation is stale-safe) is
 *   scheduled in its own transaction, which projects it to AS.
 * - `running`: reported only (operator view, ResumeStrandedProcess /
 *   FailStrandedProcess repairs); an automatic re-run would repeat effects.
 */
final class WpStrandedScan {

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly IProcessStore $store,
    private readonly IWakeupScheduler $wakeups,
    private readonly ?IClock $clock = null,
  ) {}

  public function run(): WpStrandedReport {
    $now = ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now();
    $minted = $queued = $running = [];

    foreach ($this->store->findStranded($now) as $s) {
      if ($s->status === 'running') {
        $running[] = $s;
        continue;
      }
      if (function_exists('as_has_scheduled_action')
        && as_has_scheduled_action($this->config->hook('process_continue'), ['process_id' => $s->processId])) {
        $queued[] = $s->processId;
        continue;
      }
      (new WpdbTransactionBoundary())->run(fn () => $this->wakeups->schedule(
        WakeupIntent::continuation($this->config->prefix(), $s->processId, $s->stepIndex, $now)
      ));
      $minted[] = $s->processId;
    }

    return new WpStrandedReport($minted, $queued, $running);
  }
}
