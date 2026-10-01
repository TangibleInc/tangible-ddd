<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * DBAL driver middleware of the sf conformance fixture:
 *
 * - every physical connection starts with `SET search_path TO <schema>`, so
 *   the whole host (ddd tables, Messenger table, scenario rows) lives in the
 *   test's own fresh Postgres schema, reconnects included;
 * - failNextCommit() makes the next COMMIT fail AT THE SERVER: just before
 *   the driver COMMIT it inserts a row that violates a DEFERRABLE INITIALLY
 *   DEFERRED foreign key, so Postgres rejects the COMMIT itself (23503) and
 *   rolls the transaction back. Nothing in DBAL or the boundary is stubbed.
 */
final class ScenarioSchemaMiddleware implements Middleware {

  public const FAULT_TABLE = 'conf_commit_fault';

  private ?string $failNextCommit = null;

  public function __construct(private readonly string $schema) {
    if (!preg_match('/^[a-z0-9_]+$/', $schema)) {
      throw new \InvalidArgumentException("Schema name '$schema' must match [a-z0-9_]+");
    }
  }

  public function failNextCommit(string $reason): void {
    $this->failNextCommit = $reason;
  }

  /** DDL for the fault table, run once per schema. */
  public static function faultTableSql(): array {
    return [
      'CREATE TABLE ' . self::FAULT_TABLE . '_parent (id INT PRIMARY KEY)',
      'CREATE TABLE ' . self::FAULT_TABLE . ' (parent_id INT NOT NULL, reason TEXT NOT NULL,'
        . ' FOREIGN KEY (parent_id) REFERENCES ' . self::FAULT_TABLE . '_parent (id) DEFERRABLE INITIALLY DEFERRED)',
    ];
  }

  public function wrap(Driver $driver): Driver {
    $middleware = $this;
    return new class ($driver, $middleware) extends AbstractDriverMiddleware {

      public function __construct(Driver $driver, private readonly ScenarioSchemaMiddleware $middleware) {
        parent::__construct($driver);
      }

      public function connect(#[\SensitiveParameter] array $params): DriverConnection {
        $connection = parent::connect($params);
        $connection->exec('SET search_path TO ' . $this->middleware->schema());
        return new class ($connection, $this->middleware) extends AbstractConnectionMiddleware {

          public function __construct(DriverConnection $connection, private readonly ScenarioSchemaMiddleware $middleware) {
            parent::__construct($connection);
          }

          public function commit(): void {
            $reason = $this->middleware->takeCommitFault();
            if ($reason !== null) {
              $this->exec(sprintf(
                'INSERT INTO %s (parent_id, reason) VALUES (-1, %s)',
                ScenarioSchemaMiddleware::FAULT_TABLE,
                $this->quote($reason)
              ));
            }
            parent::commit();
          }
        };
      }
    };
  }

  /** @internal */
  public function schema(): string {
    return $this->schema;
  }

  /** @internal */
  public function takeCommitFault(): ?string {
    $reason = $this->failNextCommit;
    $this->failNextCommit = null;
    return $reason;
  }
}
