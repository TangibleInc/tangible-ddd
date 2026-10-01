<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\DeliveryScenarios;

#[Group('sf')]
#[Group('conformance')]
final class SfDeliveryScenariosTest extends DeliveryScenarios {

  protected function createFixture(): HostFixture {
    return new SfHostFixture();
  }

  #[Group('delivery.delayed-once')]
  #[TestDox('delivery.delayed-once (sf, due in wave 3): skipped, Messenger schedules on the wall clock, not the host IClock (CR sfc-2)')]
  public function test_delivery_delayed_once(): void {
    // MessengerFactTransport turns the absolute due time into a DelayStamp
    // relative to the host clock, and the Doctrine transport stores
    // available_at = wall-clock now + delay. With the scenario's frozen,
    // advanced clock the transport's due time cannot equal t0 + D.
    $this->skipForChangeRequest('sfc-2', 'the scenario needs a host clock the Doctrine transport also reads (wave 3 on sf)');
  }
}
