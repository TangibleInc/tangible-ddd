<?php

declare(strict_types=1);

namespace Tangible\LMS\Extension\SuperTrace\Application\IntegrationListeners;

use Tangible\LMS\Extension\SuperTrace\Application\Commands\RecordTrace;
use Tangible\LMS\Extension\SuperTrace\Domain\Events\TraceRecorded;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\EventHandlers\IntegrationListener;
use TangibleDDD\Domain\Events\IIntegrationEvent;

final class RecordTraceWhenTraceRecorded extends IntegrationListener
{
    protected function get_event_class(): string
    {
        return TraceRecorded::class;
    }

    protected function get_command(IIntegrationEvent $event): ?ICommand
    {
        return new RecordTrace($event->trace_id);
    }
}
