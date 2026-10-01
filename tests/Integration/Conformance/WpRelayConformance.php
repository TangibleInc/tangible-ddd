<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\RelayScenarios;
use TangibleDDD\Runtime\Outbox\Claim;

/**
 * The process-free relay scenarios on wp, on the schema v8 outbox
 * (claim_token fencing, the requested lease, v8 pause rows): every id of
 * RelayScenarios runs. The wp host adds one check while a lease is live:
 * a 0.6 copy's fetch_pending() skips the claimed row (`locked_until` set).
 */
#[Group('wp')]
final class WpRelayConformance extends RelayScenarios {

  protected function create_fixture(): HostFixture {
    return new WpHostFixture();
  }

  /** While a port claim is live, a 0.6 copy's fetch_pending() must skip the row (`locked_until` set). */
  protected function while_leased(Claim $c): void {
    self::assertInstanceOf(WpHostFixture::class, $this->host);
    self::assertNotContains($c->event_id, $this->host->legacyFetchPending(), 'a 0.6 fetch_pending() skips the leased row');
  }
}
