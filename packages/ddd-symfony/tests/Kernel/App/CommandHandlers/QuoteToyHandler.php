<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\CommandHandlers;

use TangibleDDD\Application\CommandHandlers\IReturningCommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\QuoteToyCommand;

/** L1: autoconfigured with the command-handler tag although it is not an ICommandHandler. */
final class QuoteToyHandler implements IReturningCommandHandler {

  public function handle(ICommand $command): mixed {
    assert($command instanceof QuoteToyCommand);
    return ['team' => $command->team_id, 'quote' => 500];
  }
}
