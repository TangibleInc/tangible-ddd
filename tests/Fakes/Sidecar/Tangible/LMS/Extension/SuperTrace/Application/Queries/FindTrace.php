<?php

declare(strict_types=1);

namespace Tangible\LMS\Extension\SuperTrace\Application\Queries;

use TangibleDDD\Application\Queries\SelfHandlingQuery;

final class FindTrace extends SelfHandlingQuery
{
    public function __construct(public readonly string $trace_id) {}
}
