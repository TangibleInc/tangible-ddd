<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Native;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\EffectStateScenarios;
use TangibleDDD\Core\Tests\Pdo\Conformance\PdoHostFixture;

#[Group('pdo')]
#[Group('pdo-native')]
final class PdoNativeEffectStateScenariosTest extends EffectStateScenarios {

  protected function create_fixture(): HostFixture {
    return new PdoHostFixture(emulatePrepares: false);
  }
}
