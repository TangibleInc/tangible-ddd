<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\AwaitScenarios;

#[Group('mem')]
final class MemAwaitScenariosTest extends AwaitScenarios {

  protected function createFixture(): HostFixture {
    return new MemHostFixture();
  }
}
