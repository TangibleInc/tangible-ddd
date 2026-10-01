<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\DeliveryScenarios;

/**
 * delivery.delayed-once runs unchanged since wave 3: SfHostFixture reports
 * the Doctrine transport's `available_at` on the host clock (CR sfc-2
 * option (b), W3CP-R1).
 */
#[Group('sf')]
#[Group('conformance')]
final class SfDeliveryScenariosTest extends DeliveryScenarios {

  protected function createFixture(): HostFixture {
    return new SfHostFixture();
  }
}
