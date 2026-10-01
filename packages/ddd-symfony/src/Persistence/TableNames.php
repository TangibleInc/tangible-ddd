<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Persistence;

use TangibleDDD\Runtime\ITableNames;

/**
 * Table names of one consumer's ddd tables on Postgres: an optional Postgres
 * schema plus a table prefix (wave 5, multi-consumer). The bundle passes one
 * string to every store, `[schema.]prefix`:
 *
 * - `''` or `b_`: tables in the connection's search path, `b_ddd_outbox`;
 * - `billing.` or `billing.b_`: tables in schema `billing`,
 *   `billing.b_ddd_outbox` (index and constraint names stay unqualified, as
 *   Postgres requires; see PostgresSchema::render()).
 *
 * The same rules as core's PrefixedTableNames otherwise (which it replaces in
 * ddd-symfony): the prefix is [A-Za-z0-9_]*, the schema [a-z_][a-z0-9_]*, the
 * logical name [a-z0-9_]+; anything else is an \InvalidArgumentException.
 */
final class TableNames implements ITableNames {

  private function __construct(
    private readonly ?string $schema,
    private readonly string $prefix,
  ) {}

  /** @param string $qualified `[schema.]prefix` */
  public static function of(string $qualified): self {
    $schema = null;
    $prefix = $qualified;
    if (str_contains($qualified, '.')) {
      [$schema, $prefix] = explode('.', $qualified, 2);
      if (!preg_match('/^[a-z_][a-z0-9_]*$/', $schema)) {
        throw new \InvalidArgumentException("Postgres schema '$schema' must match [a-z_][a-z0-9_]*");
      }
    }
    if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
      throw new \InvalidArgumentException("Table prefix '$prefix' must match [A-Za-z0-9_]*");
    }
    return new self($schema, $prefix);
  }

  /** The `[schema.]prefix` string for a schema (null: none) and a prefix. */
  public static function join(?string $schema, string $prefix): string {
    return self::of(($schema === null || $schema === '' ? '' : $schema . '.') . $prefix)->qualified();
  }

  public function table(string $logical): string {
    if (!preg_match('/^[a-z0-9_]+$/', $logical)) {
      throw new \InvalidArgumentException("Logical table name '$logical' must match [a-z0-9_]+");
    }
    return $this->qualified() . $logical;
  }

  public function schema(): ?string {
    return $this->schema;
  }

  /** The prefix without the schema (index and constraint names). */
  public function prefix(): string {
    return $this->prefix;
  }

  public function qualified(): string {
    return ($this->schema === null ? '' : $this->schema . '.') . $this->prefix;
  }
}
