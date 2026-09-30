<?php

namespace TangibleDDD\Application\Infrastructure;

use TangibleDDD\Application\Process\LongProcess;

/**
 * A long process (saga) ended in the failed state — either a step threw and
 * compensation could not recover, or compensation itself failed.
 *
 * A saga IS a doctrinal causer (orchestration), so this carries causation =
 * the process: a reaction command is legitimately "caused by process P".
 * Correlation is the saga's own trace.
 */
final class ProcessFailed extends InfrastructureEvent {

  public function __construct(
    LongProcess $process,
    public readonly string $error = '',
  ) {
    parent::__construct(
      $process,
      $process->correlation_id(),
      (string) $process->get_id(),
      'long_process',
    );
  }

  public static function action(): string {
    return 'process_failed';
  }

  public function process(): LongProcess {
    return $this->subject;
  }
}
