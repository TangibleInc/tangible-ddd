<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\ParkedAnswerScenarios;

/** AW2 on the mem host with the fact-carrying scheduler (InMemoryParkingScheduler). */
#[Group('mem')]
final class MemParkedAnswerScenariosTest extends ParkedAnswerScenarios {

  protected function create_fixture(): HostFixture {
    return new MemHostFixture(parks_facts: true);
  }
}
