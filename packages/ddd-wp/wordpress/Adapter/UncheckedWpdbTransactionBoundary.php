<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\ITransactionBoundary;
use Throwable;
use wpdb;

/**
 * The 0.6 transaction behaviour of TransactionMiddleware, as an
 * ITransactionBoundary, so the legacy class can be the R2 subclass of the core
 * TransactionalCommandMiddleware without changing what it does (register 1.4):
 *
 * - START TRANSACTION / COMMIT results are NOT checked (0.6
 *   TransactionMiddleware.php:39,43);
 * - on a throw it issues ROLLBACK, ignores any rollback error, and rethrows
 *   the original;
 * - no nesting policy: a nested run issues its own START/COMMIT, which MySQL
 *   treats as an implicit commit, exactly as 0.6 did.
 *
 * @internal Only TransactionMiddleware uses it. The checked WordPress
 *   boundary for new wiring (WpdbTransactionBoundary) is round-2 port work.
 */
final class UncheckedWpdbTransactionBoundary implements ITransactionBoundary {

  private int $depth = 0;

  public function __construct(private readonly wpdb $wpdb) {}

  public function run(callable $work): mixed {
    $this->depth++;
    try {
      $this->wpdb->query('START TRANSACTION');

      $result = $work();

      $this->wpdb->query('COMMIT');

      return $result;
    } catch (Throwable $e) {
      try {
        $this->wpdb->query('ROLLBACK');
      } catch (Throwable) {
        // Ignored, as in 0.6.
      }
      throw $e;
    } finally {
      $this->depth--;
    }
  }

  public function isActive(): bool {
    return $this->depth > 0;
  }
}
