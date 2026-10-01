<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemSimulatedHostFixture;
use TangibleDDD\Conformance\Scenarios\ProcessDeliveryScenarios;

/** The .process variants under the deferred start mode (sf's default). Not a host result (MemSimulatedHostFixture). */
#[Group('simulated')]
final class DeferredStartProcessDeliveryScenariosTest extends ProcessDeliveryScenarios {

  protected function create_fixture(): HostFixture {
    return new MemSimulatedHostFixture(StartMode::Deferred);
  }
}
