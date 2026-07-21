<?php

declare(strict_types=1);

namespace Tangible\LMS\Extension\SuperTrace\Application\Commands;

use TangibleDDD\Application\Commands\SelfHandlingCommand;

final class RecordTrace extends SelfHandlingCommand
{
    public function __construct(public readonly string $trace_id) {}
}
