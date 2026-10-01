<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemSimulatedHostFixture;
use TangibleDDD\Conformance\Scenarios\ConcurrencyScenarios;

/** Two workers on the simulated second connection. Not a host result (MemSimulatedHostFixture). */
#[Group('simulated')]
final class SimulatedConcurrencyScenariosTest extends ConcurrencyScenarios {

  protected function createFixture(): HostFixture {
    return new MemSimulatedHostFixture(StartMode::InBand);
  }
}
