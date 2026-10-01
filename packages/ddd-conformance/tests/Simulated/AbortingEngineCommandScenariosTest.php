<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemSimulatedHostFixture;
use TangibleDDD\Conformance\Scenarios\CommandScenarios;

/** The cmd.* scenarios on a simulated engine that aborts on a statement error, so cmd.commit-failure's CR sf-7 branch runs. Not a host result. */
#[Group('simulated')]
final class AbortingEngineCommandScenariosTest extends CommandScenarios {

  protected function create_fixture(): HostFixture {
    return new MemSimulatedHostFixture(StartMode::InBand, abortOnStatementError: true);
  }
}
