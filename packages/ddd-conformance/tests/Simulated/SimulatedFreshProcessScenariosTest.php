<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemSimulatedHostFixture;
use TangibleDDD\Conformance\Scenarios\FreshProcessScenarios;

/** The multi-process ids against the in-process simulation. Not a host result (MemSimulatedHostFixture). */
#[Group('simulated')]
final class SimulatedFreshProcessScenariosTest extends FreshProcessScenarios {

  protected function create_fixture(): HostFixture {
    return new MemSimulatedHostFixture(StartMode::InBand);
  }
}
