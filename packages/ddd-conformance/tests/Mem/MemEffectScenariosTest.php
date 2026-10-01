<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\EffectScenarios;

#[Group('mem')]
final class MemEffectScenariosTest extends EffectScenarios {

  protected function createFixture(): HostFixture {
    return new MemHostFixture();
  }
}
