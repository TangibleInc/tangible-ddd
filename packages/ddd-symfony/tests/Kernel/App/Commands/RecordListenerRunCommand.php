<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\CQRS\CommandBusAware;

/** What the integration listeners translate a fact into. */
final class RecordListenerRunCommand implements ICommand, ITransactionalCommand {
  use CommandBusAware;

  public function __construct(public readonly string $listener, public readonly string $widget_id) {}
}
