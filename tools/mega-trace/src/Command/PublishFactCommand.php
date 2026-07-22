<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Command;

use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IIntegrationEvent;

abstract class PublishFactCommand extends SelfHandlingCommand implements ITransactionalCommand
{
    protected const SYNTHETIC_WORK_MS = 0;

    final protected function handle(IIntegrationEventBus $events): void
    {
        SyntheticWorkload::spend($this->synthetic_workload_ms());
        $events->publish($this->fact());
    }

    final public function synthetic_workload_ms(): int
    {
        return static::SYNTHETIC_WORK_MS;
    }

    abstract protected function fact(): IIntegrationEvent;
}
