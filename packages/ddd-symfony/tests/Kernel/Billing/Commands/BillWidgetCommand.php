<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\Billing\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\CQRS\CommandBusAware;

/** Consumer `bil`: what its listener translates the app's WidgetRegistered into. */
final class BillWidgetCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;

  public function __construct(public readonly string $widget_id) {}
}
