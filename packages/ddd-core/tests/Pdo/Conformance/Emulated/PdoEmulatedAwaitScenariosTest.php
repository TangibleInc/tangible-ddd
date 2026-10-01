<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Conformance\Emulated;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\AwaitScenarios;
use TangibleDDD\Core\Tests\Pdo\Conformance\PdoHostFixture;

#[Group('pdo')]
#[Group('pdo-emulated')]
final class PdoEmulatedAwaitScenariosTest extends AwaitScenarios {

  protected function create_fixture(): HostFixture {
    return new PdoHostFixture(emulatePrepares: true);
  }
}
