<?php

declare(strict_types=1);

namespace Tangible\LMS\Extension\SuperTrace\Application\Process;

use Tangible\LMS\Extension\SuperTrace\Application\Commands\RecordTrace;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

final class SuperTraceProcess extends LongProcess
{
    public function __construct(private readonly string $trace_id)
    {
        parent::__construct(null);
    }

    protected function record(): Result
    {
        return new Result(commands: [new RecordTrace($this->trace_id)]);
    }
}
