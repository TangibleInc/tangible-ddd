<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process\Repair;

use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Process\StrandedProcess;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;
use TangibleDDD\Runtime\Support\Log;

/**
 * Handles ResumeStrandedProcess: after the guards (StrandedRepair), fences
 * the row (IProcessStore::touch at the guarded version) and writes the wake
 * that re-runs the stranded step in a worker. `running`: a ResumeRetry
 * intent (expected status `running`, the row's step and the fenced
 * version), due now; `scheduled`: a Continue intent at the row's
 * step, due now. Keys carry a `repair-<UTC time>` nonce, so a second repair
 * later is a new intent. Error behaviour: ProcessNotStranded from a guard,
 * \LogicException without ports, storage failures propagate.
 */
final class ResumeStrandedProcessHandler extends StrandedRepair implements ICommandHandler {

  public function handle(ICommand $command): void {
    if (!$command instanceof ResumeStrandedProcess) {
      return;
    }
    $prefix = $command->consumer_prefix;

    $this->guarded(
      $prefix,
      $command->process_id,
      $command->expected_version,
      static function (LongProcess $process, StrandedProcess $row, int $version, IProcessStore $store, IWakeupScheduler $wakeups, \DateTimeImmutable $now) use ($prefix): void {
        // Fence the row first (see StrandedRepair: the lock may be released
        // before the command's transaction commits); the wake expects the
        // fenced version.
        $version = $store->touch($row->process_id, $version);
        $nonce = 'repair-' . $now->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis.u');
        $intent = $row->status === 'scheduled'
          ? WakeupIntent::continuation($prefix, $row->process_id, $row->step_index, $now, $nonce)
          : WakeupIntent::resume_retry($prefix, $row->process_id, $row->step_index, 'running', $version, $now, $nonce);
        $wakeups->schedule($intent);
        Log::write(null, sprintf(
          '[%s process] operator resumed stranded process #%d (%s at step %d, version %d) as %s',
          $prefix, $row->process_id, $row->status, $row->step_index, $version, $intent->key
        ), 'warning');
      },
    );
  }
}
