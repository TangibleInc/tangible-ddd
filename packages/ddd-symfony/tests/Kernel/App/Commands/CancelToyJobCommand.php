<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\CQRS\CommandBusAware;

/** Reference scenario: the compensation of the order step. */
final class CancelToyJobCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;

  public function __construct(public readonly string $team_id, public readonly string $reason) {}
}
