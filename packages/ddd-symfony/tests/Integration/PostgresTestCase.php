<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;

/**
 * Base for adapter tests on Postgres 16. Each test gets freshly created ddd
 * tables on its own connection; nothing is wrapped in a per-test transaction.
 */
abstract class PostgresTestCase extends TestCase {

  protected Connection $db;

  /** @var list<Connection> */
  private array $extra = [];

  protected function setUp(): void {
    $this->db = PostgresDatabase::connect();
    PostgresDatabase::resetDddSchema($this->db);
  }

  protected function tearDown(): void {
    if ($this->db->isTransactionActive()) {
      $this->db->rollBack();
    }
    $this->db->close();
    foreach ($this->extra as $c) {
      $c->close();
    }
    $this->extra = [];
  }

  /** A second, independent session (another worker / process). */
  protected function secondConnection(): Connection {
    return $this->extra[] = PostgresDatabase::connect();
  }
}
