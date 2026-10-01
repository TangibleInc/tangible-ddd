<?php

declare(strict_types=1);

namespace Example\PlainPhpDurable;

use TangibleDDD\Defaults\Pdo\IHostConnection;

/**
 * IHostConnection over an existing \mysqli handle (register 3.3): what a
 * CodeIgniter-style host on its default MySQLi driver writes, passing
 * `$db->connID` (the handle its own models already use), so domain writes
 * and the outbox share one transaction. Positional `?` parameters only
 * (all ddd-core uses); ints and bools bind as integers, null as NULL.
 */
final class MysqliConnection implements IHostConnection {

  private bool $inTransaction = false;

  public function __construct(private readonly \mysqli $db) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // every failure throws mysqli_sql_exception
  }

  public function execute(string $sql, array $params = []): int {
    return (int) $this->run($sql, $params)->affected_rows;
  }

  public function fetchAll(string $sql, array $params = []): array {
    $result = $this->run($sql, $params)->get_result();
    return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
  }

  public function fetchOne(string $sql, array $params = []): ?array {
    return $this->fetchAll($sql, $params)[0] ?? null;
  }

  public function lastInsertId(): string { return (string) $this->db->insert_id; }
  public function begin(): void { $this->db->begin_transaction(); $this->inTransaction = true; }
  public function commit(): void { $this->inTransaction = false; $this->db->commit(); }
  public function rollBack(): void { $this->inTransaction = false; $this->db->rollback(); }
  public function inTransaction(): bool { return $this->inTransaction; }

  public function isDuplicateKey(\Throwable $e): bool {
    return $e instanceof \mysqli_sql_exception && $e->getCode() === 1062;
  }

  private function run(string $sql, array $params): \mysqli_stmt {
    $statement = $this->db->prepare($sql);
    if ($params !== []) {
      $values = array_map(static fn ($v) => is_bool($v) ? (int) $v : $v, array_values($params));
      $types = implode('', array_map(static fn ($v) => is_int($v) ? 'i' : 's', $values));
      $statement->bind_param($types, ...$values);
    }
    $statement->execute();
    return $statement;
  }
}
