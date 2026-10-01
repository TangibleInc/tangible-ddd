<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\WorkerScenarios;

#[Group('mem')]
final class MemWorkerScenariosTest extends WorkerScenarios {

  protected function create_fixture(): HostFixture {
    return new MemHostFixture();
  }
}
