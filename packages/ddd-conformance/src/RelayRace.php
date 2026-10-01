<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/**
 * Optional HostFixture seam for the CR sf-3 assertion of
 * `relay.lease-fencing` (wave-2 notes, "carried into wave 3"; CR-W3CP-2).
 * Without it that part of the scenario is not run (the wave-1 part is).
 */
interface RelayRace {

  /**
   * Run $competitor once inside the NEXT relayOnce(), after the transport
   * took the submission and before IOutboxStore::accept(), AS ANOTHER
   * CONNECTION: what $competitor commits through HostFixture::outbox()
   * stays committed whatever the relay's own transaction does afterwards.
   *
   * sf: a second DBAL connection (the shared-connection relay holds its
   * transaction open on the first); pdo/wp: a second PDO / wpdb session;
   * mem: the competitor's outbox writes are kept across the relay's
   * rollback.
   */
  public function raceNextRelayAfterSubmit(callable $competitor): void;
}
