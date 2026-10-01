<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\ProcessDeliveryScenarios;

#[Group('sf')]
#[Group('conformance')]
final class SfProcessDeliveryScenariosTest extends ProcessDeliveryScenarios {

  protected function createFixture(): HostFixture {
    return new SfHostFixture();
  }
}
