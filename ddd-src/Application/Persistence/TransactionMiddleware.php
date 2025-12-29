<?php

namespace TangibleDDD\Application\Persistence;

use League\Tactician\Middleware;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use Throwable;
use wpdb;

/**
 * Wraps command handling in a single DB transaction.
 *
 * - Begins a transaction before invoking the next middleware/handler
 * - Commits on success
 * - Rolls back on any exception, then rethrows
 *
 * This relies on MySQL/InnoDB. On storage engines without transaction support,
 * START/COMMIT/ROLLBACK are effectively no-ops.
 */
final class TransactionMiddleware implements Middleware {

  private wpdb $wpdb;

  public function __construct(?wpdb $wpdb = null) {
    $this->wpdb = $wpdb ?: $GLOBALS['wpdb'];
  }

  public function execute($command, callable $next) {

    if (!$command instanceof ITransactionalCommand) {
      return $next($command);
    }

    if (!$this->wpdb) {
      return $next($command);
    }

    try {
      $this->wpdb->query('START TRANSACTION');

      $result = $next($command);

      $this->wpdb->query('COMMIT');

      return $result;
    } catch (Throwable $e) {
      try {
        $this->wpdb->query('ROLLBACK');
      } catch (Throwable $rollbackError) {
        // Ignore rollback errors
      }
      throw $e;
    }
  }
}
