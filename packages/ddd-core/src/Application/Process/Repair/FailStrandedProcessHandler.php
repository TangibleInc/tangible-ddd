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
 * Handles FailStrandedProcess: after the guards (StrandedRepair), a
 * version-fenced save of the row as `failed` ("Failed by operator:
 * <reason>"), or, with compensate, as `scheduled` in compensation plus a
 * Continue intent (discriminator `undo-repair-<UTC time>`) that the next
 * drain runs. Error behaviour: ProcessNotStranded from a guard,
 * ConcurrentProcessModification if the row moved between guard and save,
 * \LogicException without ports.
 */
final class FailStrandedProcessHandler extends StrandedRepair implements ICommandHandler {

  public function handle(ICommand $command): void {
    if (!$command instanceof FailStrandedProcess) {
      return;
    }
    $prefix = $command->consumer_prefix;
    $reason = 'Failed by operator: ' . $command->reason;
    $compensate = $command->compensate;

    $this->guarded(
      $prefix,
      $command->process_id,
      $command->expected_version,
      static function (LongProcess $process, StrandedProcess $row, int $version, IProcessStore $store, IWakeupScheduler $wakeups, \DateTimeImmutable $now) use ($prefix, $reason, $compensate): void {
        if (!$compensate) {
          $process->fail($reason);
          $store->save($process, $version);
        } else {
          if (!$process->is_compensating()) {
            $process->begin_compensation($reason);
          }
          $process->advance(status: 'scheduled', payload: $process->payload());
          $store->save($process, $version);
          $wakeups->schedule(WakeupIntent::continuation(
            $prefix, $row->process_id, $process->current_step_index(), $now,
            'undo-repair-' . $now->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis.u'),
          ));
        }
        Log::write(null, sprintf(
          '[%s process] operator failed stranded process #%d (%s at step %d)%s: %s',
          $prefix, $row->process_id, $row->status, $row->step_index, $compensate ? ', compensating' : '', $reason
        ), 'warning');
      },
    );
  }
}
