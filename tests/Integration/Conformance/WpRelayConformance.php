<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\RelayScenarios;
use TangibleDDD\Runtime\Outbox\Claim;

/**
 * The relay scenarios on wp. Due in wave 2: relay.invalid-acceptance,
 * relay.replay-keeps-identity. relay.crash-after-submit runs as well (due
 * in wave 3, already green on the shared $wpdb connection). The fenced
 * relay and the pause holders need schema v8 (claim_token, pause rows;
 * register sections 4 and 8, wave 3) and are skipped until then.
 */
#[Group('wp')]
final class WpRelayConformance extends RelayScenarios {

  protected function createFixture(): HostFixture {
    return new WpHostFixture();
  }

  #[Group('relay.lease-fencing')]
  public function test_relay_lease_fencing(): void {
    $this->skipForChangeRequest('wave-3 schema v8', 'the 0.6 outbox has no claim_token: WpdbOutboxStore leases a fixed 300 s regardless of $leaseSeconds and its accept/retryLater/deadLetter cannot be fenced (register 4: "on wp, the fenced relay ... wait for schema v8")');
  }

  #[Group('relay.pause-holders')]
  public function test_relay_pause_holders(): void {
    $this->skipForChangeRequest('wave-3 schema v8', 'no IRelayPauseStore on wp before the v8 pause rows; the 0.6 `{prefix}_outbox_pauses` option matches exact event types only (no selector globs)');
  }

  /** While a port claim is live, a 0.6 copy's fetch_pending() must skip the row (`locked_until` set). */
  protected function whileLeased(Claim $c): void {
    self::assertInstanceOf(WpHostFixture::class, $this->host);
    self::assertNotContains($c->event_id, $this->host->legacyFetchPending(), 'a 0.6 fetch_pending() skips the leased row');
  }
}
