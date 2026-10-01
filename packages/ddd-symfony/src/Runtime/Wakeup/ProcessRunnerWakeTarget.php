<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

use TangibleDDD\Runtime\Scheduling\WakeKind;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * Routes a due intent to the core ProcessRunner.
 *
 * When the runner has one wake door (`wake(WakeupIntent)`, requested of the
 * wave-3 core refactor, see wave3-sf-process-change-requests.md), every
 * kind goes through it, so the runner owns the stale checks and the
 * ResumeRetry semantics. Until then the 0.6-era doors are used:
 * Continue → continue_scheduled(process_id), Timeout →
 * handle_timeout(process_id, step_index). ResumeRetry and Deliver have no
 * door on the wave-2 runner and throw WakeKindUnsupported (the handler
 * exhausts the intent for the operator, it never drops it).
 *
 * $runner is the core ProcessRunner (typed object so a test double can
 * stand in; the class is final).
 */
final class ProcessRunnerWakeTarget implements IProcessWakeTarget {

  public function __construct(private readonly object $runner) {}

  public function wake(WakeupIntent $intent): void {
    if (method_exists($this->runner, 'wake')) {
      $this->runner->wake($intent);
      return;
    }

    $id = $intent->process_id ?? throw new WakeKindUnsupported("Wakeup {$intent->key} has no process id");
    match ($intent->kind) {
      WakeKind::Continue => $this->runner->continue_scheduled($id),
      WakeKind::Timeout => $this->runner->handle_timeout($id, $intent->step_index ?? throw new WakeKindUnsupported("Timeout {$intent->key} has no step index")),
      default => throw new WakeKindUnsupported(sprintf(
        'Wakeup kind %s (%s) needs ProcessRunner::wake(WakeupIntent), which this core version lacks',
        $intent->kind->value, $intent->key
      )),
    };
  }
}
