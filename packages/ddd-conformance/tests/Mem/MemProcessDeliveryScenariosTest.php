<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\ProcessDeliveryScenarios;

#[Group('mem')]
final class MemProcessDeliveryScenariosTest extends ProcessDeliveryScenarios {

  protected function create_fixture(): HostFixture {
    return new MemHostFixture();
  }
}
