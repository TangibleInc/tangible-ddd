<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\RelayScenarios;

/**
 * The relay scenarios again with a transport that shares the store's
 * connection (AS on $wpdb, the pdo jobs table, the Doctrine transport), so
 * the relay runs submit + accept in one transaction and a crash between
 * them rolls both back (CONF-5: the shared InMemoryTransport enlists in
 * InMemoryTransactionBoundary).
 */
#[Group('mem')]
final class MemSharedConnectionRelayScenariosTest extends RelayScenarios {

  protected function createFixture(): HostFixture {
    return new MemHostFixture(transportSharesConnection: true);
  }
}
