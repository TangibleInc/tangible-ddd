<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Runtime\Audit\Audit;

/** D12: opted out of the audit trail by attribute (CR-W4CE-3). */
#[Audit(false)]
final class HeartbeatCommand extends SelfHandlingCommand {

  public function __construct(public readonly string $worker = 'w') {}

  protected function handle(): string {
    return 'beat';
  }
}
