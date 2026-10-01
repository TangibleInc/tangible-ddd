<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\CommandHandlers;

use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\AnnounceCommand;

final class AnnounceHandler implements ICommandHandler {

  public function __construct(private readonly EventsUnitOfWork $events) {}

  public function handle(ICommand $command): void {
    assert($command instanceof AnnounceCommand);
    $this->events->record($command->fact);
  }
}
