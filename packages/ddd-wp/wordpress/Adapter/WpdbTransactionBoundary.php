<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\Support\Log;
use TangibleDDD\Runtime\TransactionFailed;
use Throwable;
use wpdb;

/**
 * The checked ITransactionBoundary over WordPress' global `$wpdb`
 * (register 3.2; transitional wave-2 form, CR-SM-1 left this for round 2).
 *
 * - START TRANSACTION, COMMIT and ROLLBACK results are checked: `false`
 *   from wpdb::query() throws TransactionFailed carrying `$wpdb->last_error`
 *   (a failed COMMIT is followed by a best-effort ROLLBACK).
 * - When $work throws: ROLLBACK, then the ORIGINAL exception is rethrown; a
 *   failed ROLLBACK is logged as a secondary and never replaces it.
 * - Nesting (counted per request with UncheckedWpdbTransactionBoundary, see
 *   WpdbTransactionDepth): policy Reject (default) throws
 *   NestedTransactionRejected before $work runs; Savepoint runs $work inside
 *   `SAVEPOINT` / `RELEASE SAVEPOINT` / `ROLLBACK TO SAVEPOINT`.
 *
 * The wpdb is read from the global per call unless one is injected, so a
 * test or a late `$wpdb` swap is honoured (as 0.6 did).
 */
final class WpdbTransactionBoundary implements ITransactionBoundary {

  private int $savepoints = 0;

  public function __construct(
    private readonly NestedPolicy $policy = NestedPolicy::Reject,
    private readonly ?wpdb $wpdb = null,
  ) {}

  public function run(callable $work): mixed {
    if (WpdbTransactionDepth::current() > 0) {
      if ($this->policy === NestedPolicy::Reject) {
        throw new NestedTransactionRejected('A WordPress transaction is already open on this request (policy Reject).');
      }
      return $this->savepoint($work);
    }

    $db = $this->db();
    $this->checked($db, 'START TRANSACTION');
    WpdbTransactionDepth::enter();

    try {
      $result = $work();
    } catch (Throwable $original) {
      WpdbTransactionDepth::leave();
      if ($db->query('ROLLBACK') === false) {
        Log::write(null, sprintf(
          '[ddd tx] ROLLBACK failed (%s) while handling %s: %s',
          (string) $db->last_error, get_class($original), $original->getMessage()
        ), 'error');
      }
      throw $original;
    }

    WpdbTransactionDepth::leave();
    if ($db->query('COMMIT') === false) {
      $error = (string) $db->last_error;
      $db->query('ROLLBACK');
      throw new TransactionFailed('COMMIT failed: ' . $error, 0, new \RuntimeException($error));
    }

    return $result;
  }

  public function isActive(): bool {
    return WpdbTransactionDepth::current() > 0;
  }

  private function savepoint(callable $work): mixed {
    $db = $this->db();
    $name = 'ddd_sp_' . (++$this->savepoints);
    $this->checked($db, "SAVEPOINT $name");
    WpdbTransactionDepth::enter();

    try {
      $result = $work();
    } catch (Throwable $e) {
      WpdbTransactionDepth::leave();
      if ($db->query("ROLLBACK TO SAVEPOINT $name") === false) {
        Log::write(null, sprintf('[ddd tx] ROLLBACK TO SAVEPOINT %s failed: %s', $name, (string) $db->last_error), 'error');
      }
      throw $e;
    }

    WpdbTransactionDepth::leave();
    $this->checked($db, "RELEASE SAVEPOINT $name");
    return $result;
  }

  private function checked(wpdb $db, string $sql): void {
    if ($db->query($sql) === false) {
      $error = (string) $db->last_error;
      throw new TransactionFailed("$sql failed: " . ($error !== '' ? $error : 'wpdb::query() returned false'), 0, new \RuntimeException($error));
    }
  }

  private function db(): wpdb {
    if ($this->wpdb !== null) {
      return $this->wpdb;
    }
    $db = $GLOBALS['wpdb'] ?? null;
    if (!$db instanceof wpdb) {
      throw new TransactionFailed('No WordPress database connection ($wpdb) is available.');
    }
    return $db;
  }
}
