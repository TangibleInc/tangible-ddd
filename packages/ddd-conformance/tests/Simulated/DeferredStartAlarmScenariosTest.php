<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Simulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemSimulatedHostFixture;
use TangibleDDD\Conformance\Scenarios\AlarmScenarios;

/** process.alarm-long under the deferred start mode (sf's default). Not a host result. */
#[Group('simulated')]
final class DeferredStartAlarmScenariosTest extends AlarmScenarios {

  protected function createFixture(): HostFixture {
    return new MemSimulatedHostFixture(StartMode::Deferred);
  }
}
