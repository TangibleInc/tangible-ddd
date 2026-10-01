<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\CommandHandlers;

use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Symfony\Tests\Kernel\App\Commands\RecordListenerRunCommand;
use TangibleDDD\Symfony\Tests\Kernel\App\Persistence\WidgetRepository;

final class RecordListenerRunHandler implements ICommandHandler {

  public function __construct(private readonly WidgetRepository $widgets) {}

  public function handle(ICommand $command): void {
    assert($command instanceof RecordListenerRunCommand);
    // Inside the listener's act; its causation is the enclosing fact scope.
    $this->widgets->recordListenerRun($command->listener, $command->widget_id, Correlation::peek()?->correlation_id);
  }
}
