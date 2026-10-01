<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

/**
 * IHostConnection over a PDO the HOST created and owns (register 3.3).
 *
 * - Never opens, closes or reconfigures the connection; there is no DSN
 *   handling here. The host builds the PDO with its own credentials.
 * - Requires PDO::ATTR_ERRMODE === PDO::ERRMODE_EXCEPTION: refused at
 *   construction with PdoConfigurationError, and re-checked on every call,
 *   so a host that switches it off later fails loudly instead of having
 *   driver errors silently return false (C 7.1).
 * - Binding: int (and bool, as 0/1) with PDO::PARAM_INT, so `LIMIT ?` works
 *   under emulated prepares (PDO MySQL's default); null with PARAM_NULL;
 *   string and float as strings. Anything else is refused.
 * - isDuplicateKey(): driver code MySQL 1062 (ER_DUP_ENTRY), or SQLSTATE
 *   23505 on pgsql, anywhere in the previous chain; never SQLSTATE 23000.
 *
 * Tested on MySQL 8.0 in both prepare modes; other drivers are untested.
 */
final class PdoConnection implements IHostConnection {

  private const MYSQL_DUPLICATE_ENTRY = 1062;

  public function __construct(private readonly \PDO $db) {
    $this->assertErrmode();
  }

  public function execute(string $sql, array $params = []): int {
    return $this->run($sql, $params)->rowCount();
  }

  public function fetchAll(string $sql, array $params = []): array {
    return array_values($this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC));
  }

  public function fetchOne(string $sql, array $params = []): ?array {
    $statement = $this->run($sql, $params);
    $row = $statement->fetch(\PDO::FETCH_ASSOC);
    $statement->closeCursor();
    return $row === false ? null : $row;
  }

  public function lastInsertId(): string {
    $this->assertErrmode();
    $id = $this->db->lastInsertId();
    if ($id === false) {
      throw new \RuntimeException('PDO::lastInsertId() failed');
    }
    return $id;
  }

  public function begin(): void {
    $this->assertErrmode();
    if (!$this->db->beginTransaction()) {
      throw new \RuntimeException('PDO::beginTransaction() returned false');
    }
  }

  public function commit(): void {
    $this->assertErrmode();
    if (!$this->db->commit()) {
      throw new \RuntimeException('PDO::commit() returned false');
    }
  }

  public function rollBack(): void {
    $this->assertErrmode();
    if (!$this->db->rollBack()) {
      throw new \RuntimeException('PDO::rollBack() returned false');
    }
  }

  public function inTransaction(): bool {
    return $this->db->inTransaction();
  }

  public function isDuplicateKey(\Throwable $e): bool {
    for ($t = $e; $t !== null; $t = $t->getPrevious()) {
      if (!$t instanceof \PDOException || !is_array($t->errorInfo ?? null)) {
        continue;
      }
      [$sqlState, $driverCode] = $t->errorInfo + [null, null];
      if ((int) $driverCode === self::MYSQL_DUPLICATE_ENTRY && $sqlState === '23000') {
        return true;
      }
      if ($sqlState === '23505' && $this->driver() === 'pgsql') {
        return true;
      }
    }
    return false;
  }

  /** @param array<int|string, mixed> $params */
  private function run(string $sql, array $params): \PDOStatement {
    $this->assertErrmode();
    $statement = $this->db->prepare($sql);
    foreach ($params as $key => $value) {
      $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
      [$bound, $type] = self::typed($value);
      $statement->bindValue($name, $bound, $type);
    }
    $statement->execute();
    return $statement;
  }

  /** @return array{0: mixed, 1: int} */
  private static function typed(mixed $value): array {
    return match (true) {
      $value === null => [null, \PDO::PARAM_NULL],
      is_int($value) => [$value, \PDO::PARAM_INT],
      is_bool($value) => [$value ? 1 : 0, \PDO::PARAM_INT],
      is_string($value) => [$value, \PDO::PARAM_STR],
      is_float($value) => [self::floatString($value), \PDO::PARAM_STR],
      $value instanceof \Stringable => [(string) $value, \PDO::PARAM_STR],
      default => throw new \InvalidArgumentException('Unsupported parameter type ' . get_debug_type($value) . ' (bind int, bool, string, float, Stringable or null)'),
    };
  }

  private static function floatString(float $value): string {
    if (!is_finite($value)) {
      throw new \InvalidArgumentException('Cannot bind a non-finite float');
    }
    return rtrim(rtrim(sprintf('%.17F', $value), '0'), '.') ?: '0';
  }

  private function driver(): string {
    return (string) $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
  }

  /**
   * Throws PdoConfigurationError unless the PDO is (still) in
   * ERRMODE_EXCEPTION. Every call checks it; PdoTransactionBoundary also
   * calls it at its own construction (register 3.2).
   */
  public function assertErrmode(): void {
    if ($this->db->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
      throw new PdoConfigurationError(
        'TangibleDDD\Defaults\Pdo needs the host PDO in PDO::ERRMODE_EXCEPTION '
        . '(set PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION when you create it): '
        . 'in any other mode driver errors return false and a failed COMMIT would look committed.'
      );
    }
  }
}
