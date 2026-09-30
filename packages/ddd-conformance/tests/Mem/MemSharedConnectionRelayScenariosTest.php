<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\RelayScenarios;

/**
 * The relay scenarios again with a transport that reports it shares the
 * store's connection (AS on $wpdb, the pdo jobs table, the Doctrine
 * transport), so the relay runs submit + accept in one transaction.
 */
#[Group('mem')]
final class MemSharedConnectionRelayScenariosTest extends RelayScenarios {

  protected function createFixture(): HostFixture {
    return new MemHostFixture(transportSharesConnection: true);
  }

  #[Group('relay.crash-after-submit')]
  #[TestDox('relay.crash-after-submit (shared connection): skipped, the mem transport cannot roll back a submission (CONF-5)')]
  public function test_relay_crash_after_submit(): void {
    // InMemoryTransport(sharesConnection: true) claims a shared connection
    // but is not InMemoryTransactional, so a rolled-back relay transaction
    // keeps its submission and the "both roll back, one delivery" branch
    // cannot pass on mem. The separate-connection branch runs in
    // MemRelayScenariosTest.
    $this->skipForChangeRequest('CONF-5', 'InMemoryTransport(sharesConnection: true) must enlist in InMemoryTransactionBoundary');
  }
}
