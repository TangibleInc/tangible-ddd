<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemSimulatedHostFixture;
use TangibleDDD\Conformance\Scenarios\ConcurrencyScenarios;

/**
 * Two workers on the simulated second connection, over a scheduler that
 * carries facts (CR-W5CC-7): the delivery that lost the lock is parked and
 * the drain resumes it. Not a host result (MemSimulatedHostFixture).
 */
#[Group('simulated')]
final class SimulatedParkingConcurrencyScenariosTest extends ConcurrencyScenarios {

  protected function create_fixture(): HostFixture {
    return new MemSimulatedHostFixture(StartMode::InBand, parks_facts: true);
  }
}
