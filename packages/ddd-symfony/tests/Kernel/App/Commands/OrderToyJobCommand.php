<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\CQRS\CommandBusAware;

/** Reference scenario: the step command that orders the toy job (TXP's RunnerJobOrdered role). */
final class OrderToyJobCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;

  public function __construct(
    public readonly string $team_id,
    public readonly string $job_id,
    public readonly int $process_id,
    public readonly int $step_index,
  ) {}
}
