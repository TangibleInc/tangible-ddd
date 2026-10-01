<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\DeliveryScenarios;

#[Group('wp')]
final class WpDeliveryConformance extends DeliveryScenarios {

  protected function createFixture(): HostFixture {
    return new WpHostFixture();
  }
}
