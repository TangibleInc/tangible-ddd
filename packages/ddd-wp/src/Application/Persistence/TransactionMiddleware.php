<?php

namespace TangibleDDD\Application\Persistence;

use TangibleDDD\WordPress\Adapter\UncheckedWpdbTransactionBoundary;
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
 *
 * Wave 2 (rule R2): the legacy WordPress FQCN, owned by ddd-wp, as a thin
 * subclass of the portable TransactionalCommandMiddleware. The 0.6.5
 * constructor stays callable exactly as compiled consumer containers call it
 * (`new TransactionMiddleware()`), and so does its behaviour: outside WordPress
 * the no-argument constructor throws a TypeError (the typed property rejects
 * a null $GLOBALS['wpdb']), and inside WordPress START/COMMIT results stay
 * unchecked (UncheckedWpdbTransactionBoundary).
 */
final class TransactionMiddleware extends TransactionalCommandMiddleware {

  private wpdb $wpdb;

  public function __construct(?wpdb $wpdb = null) {
    $this->wpdb = $wpdb ?: $GLOBALS['wpdb'];
    parent::__construct(new UncheckedWpdbTransactionBoundary($this->wpdb));
  }
}
