<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Emulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\RelayScenarios;
use TangibleDDD\Core\Tests\Pdo\Conformance\PdoHostFixture;

#[Group('pdo')]
#[Group('pdo-emulated')]
final class PdoEmulatedRelayScenariosTest extends RelayScenarios {

  protected function create_fixture(): HostFixture {
    return new PdoHostFixture(emulatePrepares: true);
  }
}
