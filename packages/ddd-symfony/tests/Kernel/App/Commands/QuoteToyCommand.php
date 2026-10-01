<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\CQRS\CommandBusAware;

/** L1 / D11: a plain command whose handler is an IReturningCommandHandler. */
final class QuoteToyCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;

  public function __construct(public readonly string $team_id) {}
}
