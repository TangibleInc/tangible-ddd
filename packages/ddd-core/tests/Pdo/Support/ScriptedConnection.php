<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Support;

use TangibleDDD\Defaults\Pdo\IHostConnection;

/**
 * An IHostConnection whose fetchOne() answers come from a script: each entry
 * is a row (array), null, or a Throwable to throw. Used to make GET_LOCK
 * return what a live server only returns under faults (NULL, an error).
 */
final class ScriptedConnection implements IHostConnection {

  /** @var list<string> */
  public array $queries = [];

  /** @param list<array<string, mixed>|null|\Throwable> $answers */
  public function __construct(private array $answers) {}

  public function execute(string $sql, array $params = []): int {
    $this->queries[] = $sql;
    return 0;
  }

  public function fetchAll(string $sql, array $params = []): array {
    $row = $this->fetchOne($sql, $params);
    return $row === null ? [] : [$row];
  }

  public function fetchOne(string $sql, array $params = []): ?array {
    $this->queries[] = $sql;
    $answer = array_shift($this->answers);
    if ($answer instanceof \Throwable) {
      throw $answer;
    }
    return $answer;
  }

  public function lastInsertId(): string {
    return '0';
  }

  public function begin(): void {}

  public function commit(): void {}

  public function rollBack(): void {}

  public function inTransaction(): bool {
    return false;
  }

  public function isDuplicateKey(\Throwable $e): bool {
    return false;
  }
}
