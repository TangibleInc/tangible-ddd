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
 * For every `IProcessStore::find_stranded()` row:
 * - `scheduled`: if Action Scheduler already holds a pending or running
 *   `{prefix}_process_continue` action with the legacy args
 *   ['process_id' => id] (queued by a 0.6 copy, or by N before a
 *   rollback), NO intent is minted: the legacy args cannot carry the step
 *   index, so a second continuation would re-run a step after a rollback.
 *   Otherwise a fresh Continue intent (continuation is stale-safe) is
 *   scheduled in its own transaction, which projects it to AS.
 * - `scheduled` with an `exhausted` wake (register 5.1): reported, never
 *   re-minted; `wp ddd ops --rearm=<key>` restarts it.
 * - `running` (old, and its process lock free on both names, so no wake
 *   is still holding it): reported only, in the operator view; the
 *   ResumeStrandedProcess / FailStrandedProcess repairs are pending core
 *   (WP8-10). An automatic re-run would repeat effects.
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
    $minted = $queued = $running = $exhausted = [];

    foreach ($this->store->find_stranded($now) as $s) {
      if ($s->status === 'running') {
        $running[] = $s;
        continue;
      }
      if ($this->wakeups instanceof WpdbWakeupScheduler && $this->wakeups->has_exhausted_intent($s->process_id)) {
        $exhausted[] = $s->process_id; // the wake budget is spent: the operator re-arms it, the scan does not
        continue;
      }
      if (function_exists('as_has_scheduled_action')
        && as_has_scheduled_action($this->config->hook('process_continue'), ['process_id' => $s->process_id])) {
        $queued[] = $s->process_id;
        continue;
      }
      (new WpdbTransactionBoundary())->run(fn () => $this->wakeups->schedule(
        WakeupIntent::continuation($this->config->prefix(), $s->process_id, $s->step_index, $now)
      ));
      $minted[] = $s->process_id;
    }

    return new WpStrandedReport($minted, $queued, $running, $exhausted);
  }
}
