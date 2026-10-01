<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\LockScenarios;

/**
 * The lock scenarios on the mem host whose scheduler carries facts
 * (InMemoryParkingScheduler, ICarriesFacts; CR-W5CC-7): lock.acquire-error
 * takes its parking branch. MemLockScenariosTest keeps the wave-3 branch.
 */
#[Group('mem')]
final class MemParkingLockScenariosTest extends LockScenarios {

  protected function create_fixture(): HostFixture {
    return new MemHostFixture(parks_facts: true);
  }
}
