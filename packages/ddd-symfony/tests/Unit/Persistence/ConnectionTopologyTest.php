<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Symfony\Persistence\ConnectionTopology;

final class ConnectionTopologyTest extends TestCase {

  public function test_a_neon_pooler_host_is_pooled(): void {
    self::assertNotNull(ConnectionTopology::pooler(['host' => 'ep-calm-1234-pooler.eu-central-1.aws.neon.tech', 'port' => 5432]));
  }

  public function test_the_pgbouncer_port_is_pooled(): void {
    self::assertNotNull(ConnectionTopology::pooler(['host' => 'db.internal', 'port' => 6432]));
    self::assertNotNull(ConnectionTopology::pooler(['host' => 'db.internal', 'port' => '6432']));
  }

  public function test_a_pooled_primary_or_replica_is_pooled(): void {
    self::assertNotNull(ConnectionTopology::pooler(['primary' => ['host' => 'x-pooler.neon.tech']]));
    self::assertNotNull(ConnectionTopology::pooler(['host' => 'direct', 'replica' => [['host' => 'r', 'port' => 6432]]]));
  }

  public function test_a_direct_endpoint_is_not_pooled(): void {
    self::assertNull(ConnectionTopology::pooler(['host' => 'ep-calm-1234.eu-central-1.aws.neon.tech', 'port' => 5432]));
    self::assertNull(ConnectionTopology::pooler(['host' => '127.0.0.1', 'port' => 55432]));
    self::assertNull(ConnectionTopology::pooler([]));
  }
}
