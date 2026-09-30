<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/** ITableNames for hosts with a fixed prefix (pdo, sf, mem). */
final class PrefixedTableNames implements ITableNames {

  public function __construct(private readonly string $prefix) {
    if (!preg_match('/^[A-Za-z0-9_]*$/', $prefix)) {
      throw new \InvalidArgumentException("Table prefix '$prefix' must match [A-Za-z0-9_]*");
    }
  }

  public function table(string $logical): string {
    if (!preg_match('/^[a-z0-9_]+$/', $logical)) {
      throw new \InvalidArgumentException("Logical table name '$logical' must match [a-z0-9_]+");
    }
    return $this->prefix . $logical;
  }
}
