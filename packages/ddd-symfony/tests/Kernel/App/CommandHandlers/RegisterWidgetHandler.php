<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\CommandHandlers;

use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RegisterWidgetCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Events\WidgetRegistered;
use TangibleDDD\Symfony\Tests\Kernel\App\Persistence\WidgetRepository;

final class RegisterWidgetHandler implements ICommandHandler {

  public function __construct(private readonly WidgetRepository $widgets, private readonly EventsUnitOfWork $events) {}

  public function handle(ICommand $command): void {
    assert($command instanceof RegisterWidgetCommand);
    $this->widgets->insert($command->widget_id, $command->name);
    $this->events->record(new WidgetRegistered($command->widget_id));
    if ($command->failAfterWrite) {
      throw new \DomainException('handler failed after writing');
    }
  }
}
