<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemSimulatedHostFixture;
use TangibleDDD\Conformance\Scenarios\WebStartScenarios;

/** process.start-from-web with the deferred start mode sf uses. Not a host result (MemSimulatedHostFixture). */
#[Group('simulated')]
final class SimulatedWebStartScenariosTest extends WebStartScenarios {

  protected function create_fixture(): HostFixture {
    return new MemSimulatedHostFixture(StartMode::Deferred);
  }
}
