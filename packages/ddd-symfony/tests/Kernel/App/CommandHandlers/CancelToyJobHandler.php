<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\CommandHandlers;

use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\CancelToyJobCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Persistence\WidgetRepository;

final class CancelToyJobHandler implements ICommandHandler {

  public function __construct(private readonly WidgetRepository $runs) {}

  public function handle(ICommand $command): void {
    assert($command instanceof CancelToyJobCommand);
    $this->runs->recordListenerRun('toy-cancel', $command->team_id, $command->reason);
  }
}
