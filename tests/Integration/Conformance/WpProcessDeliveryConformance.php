<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\ProcessDeliveryScenarios;

/** ProcessDeliveryScenarios on wp: the four .process variants (real ProcessRunner on the v8 wp ports). */
#[Group('wp')]
final class WpProcessDeliveryConformance extends ProcessDeliveryScenarios {

  protected function createFixture(): HostFixture {
    return new WpHostFixture();
  }
}
