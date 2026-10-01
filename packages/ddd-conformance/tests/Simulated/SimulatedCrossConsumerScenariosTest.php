<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemCrossConsumerFixture;
use TangibleDDD\Conformance\Scenarios\CrossConsumerScenarios;

/** Two consumers simulated over the mem host. Not a host result (MemCrossConsumerFixture). */
#[Group('simulated')]
final class SimulatedCrossConsumerScenariosTest extends CrossConsumerScenarios {

  protected function create_fixture(): HostFixture {
    return new MemCrossConsumerFixture();
  }
}
