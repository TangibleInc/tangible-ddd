<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\SelfHandlingCommand;

/** D12: no attribute; the `audit` kernel variant lists it under audit.without_parameters. */
final class ReportProgressCommand extends SelfHandlingCommand implements ProgressCommand {

  public function __construct(public readonly int $percent) {}

  protected function handle(): int {
    return $this->percent;
  }
}
