<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemSimulatedHostFixture;
use TangibleDDD\Conformance\Scenarios\AwaitScenarios;

/** The D3 await scenarios under the deferred start mode (sf's default). Not a host result. */
#[Group('simulated')]
final class DeferredStartAwaitScenariosTest extends AwaitScenarios {

  protected function createFixture(): HostFixture {
    return new MemSimulatedHostFixture(StartMode::Deferred);
  }
}
