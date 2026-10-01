<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\CQRS\CommandBusAware;

final class RegisterWidgetCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;

  public function __construct(
    public readonly string $widget_id,
    public readonly string $name = 'widget',
    public readonly bool $failAfterWrite = false,
  ) {}
}
