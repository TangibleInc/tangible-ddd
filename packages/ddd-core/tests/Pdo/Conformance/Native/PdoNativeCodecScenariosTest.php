<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Native;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\CodecScenarios;
use TangibleDDD\Core\Tests\Pdo\Conformance\PdoHostFixture;

#[Group('pdo')]
#[Group('pdo-native')]
final class PdoNativeCodecScenariosTest extends CodecScenarios {

  protected function createFixture(): HostFixture {
    return new PdoHostFixture(emulatePrepares: false);
  }
}
