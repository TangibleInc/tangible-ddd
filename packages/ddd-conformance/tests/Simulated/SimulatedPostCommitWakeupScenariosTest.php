<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemSimulatedHostFixture;
use TangibleDDD\Conformance\Scenarios\PostCommitWakeupScenarios;

/** wakeup.post-commit (sf only) on the simulated post-commit wakeup. Not a host result. */
#[Group('simulated')]
final class SimulatedPostCommitWakeupScenariosTest extends PostCommitWakeupScenarios {

  protected function createFixture(): HostFixture {
    return new MemSimulatedHostFixture();
  }
}
