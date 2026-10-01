<?php

declare(strict_types=1);

namespace TangibleDDD\Defaults\Pdo;

/**
 * The host's own database connection (register 3.3): the "`$db` somewhere"
 * of a plain-PHP host. The host passes the connection its own repositories
 * already use; that shared connection is the only thing atomicity needs.
 * ddd-core never opens, closes or reconfigures it.
 *
 * Error behaviour: every method throws on failure; nothing returns false.
 *
 * Parameters: a list binds positional `?` placeholders, a string-keyed map
 * binds `:name` placeholders. `int` (and `bool`, as 0/1) values are bound as
 * integers, so `LIMIT ?` works under emulated prepares; `null` as NULL;
 * everything else as a string.
 *
 * isDuplicateKey() matches the driver error code of a unique-key violation
 * only (MySQL 1062, Postgres 23505), never the SQLSTATE class 23000, which
 * also covers foreign-key and NOT NULL violations and would make
 * insertIgnited() silently drop an ignition.
 *
 * A host on another driver (CodeIgniter 4 on MySQLi, for instance)
 * implements this interface over its own handle in about 40 lines.
 */
interface IHostConnection {

  /** @param array<int|string, mixed> $params @return int affected rows */
  public function execute(string $sql, array $params = []): int;

  /** @param array<int|string, mixed> $params @return list<array<string, mixed>> */
  public function fetchAll(string $sql, array $params = []): array;

  /** @param array<int|string, mixed> $params @return array<string, mixed>|null the first row, or null */
  public function fetchOne(string $sql, array $params = []): ?array;

  public function lastInsertId(): string;

  public function begin(): void;

  public function commit(): void;

  public function rollBack(): void;

  public function inTransaction(): bool;

  public function isDuplicateKey(\Throwable $e): bool;
}
