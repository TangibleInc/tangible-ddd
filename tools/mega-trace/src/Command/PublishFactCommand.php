<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Command;

use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IIntegrationEvent;

abstract class PublishFactCommand extends SelfHandlingCommand implements ITransactionalCommand
{
    final protected function handle(IIntegrationEventBus $events): void
    {
        $events->publish($this->fact());
    }

    abstract protected function fact(): IIntegrationEvent;
}
