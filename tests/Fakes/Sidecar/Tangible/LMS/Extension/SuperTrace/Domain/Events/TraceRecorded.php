<?php

declare(strict_types=1);

namespace Tangible\LMS\Extension\SuperTrace\Domain\Events;

use TangibleDDD\Domain\Events\IntegrationEvent;

final class TraceRecorded extends IntegrationEvent
{
    public function __construct(public readonly string $trace_id) {}
}
