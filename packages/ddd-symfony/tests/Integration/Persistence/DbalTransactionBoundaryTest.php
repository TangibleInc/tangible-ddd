<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\TransactionFailed;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;

final class DbalTransactionBoundaryTest extends PostgresTestCase {

  protected function setUp(): void {
    parent::setUp();
    $this->db->executeStatement('DROP TABLE IF EXISTS sf_tx_rows');
    $this->db->executeStatement('DROP TABLE IF EXISTS sf_tx_deferred');
    $this->db->executeStatement('CREATE TABLE sf_tx_rows (id TEXT PRIMARY KEY)');
    // A deferred constraint fails at COMMIT, not at the INSERT: the driver-level commit failure.
    $this->db->executeStatement(
      'CREATE TABLE sf_tx_deferred (id INT PRIMARY KEY, parent INT NULL,
        CONSTRAINT sf_tx_deferred_parent FOREIGN KEY (parent) REFERENCES sf_tx_deferred (id) DEFERRABLE INITIALLY DEFERRED)'
    );
  }

  public function test_run_commits_and_returns_the_work_value_unchanged(): void {
    $boundary = new DbalTransactionBoundary($this->db);
    $receipt = new \stdClass();

    $result = $boundary->run(function () use ($receipt) {
      $this->db->insert('sf_tx_rows', ['id' => 'a']);
      return $receipt;
    });

    self::assertSame($receipt, $result);
    self::assertFalse($this->db->isTransactionActive());
    self::assertSame(1, (int) $this->secondConnection()->fetchOne('SELECT count(*) FROM sf_tx_rows'));
  }

  public function test_a_throwing_work_rolls_back_and_rethrows_the_original(): void {
    $boundary = new DbalTransactionBoundary($this->db);
    $original = new \DomainException('handler said no');

    try {
      $boundary->run(function () use ($original) {
        $this->db->insert('sf_tx_rows', ['id' => 'a']);
        throw $original;
      });
      self::fail('expected the original exception');
    } catch (\DomainException $e) {
      self::assertSame($original, $e);
    }

    self::assertFalse($this->db->isTransactionActive());
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM sf_tx_rows'));
  }

  public function test_is_active_reflects_the_connection(): void {
    $boundary = new DbalTransactionBoundary($this->db);
    self::assertFalse($boundary->isActive());
    $seen = $boundary->run(fn () => $boundary->isActive());
    self::assertTrue($seen);
    self::assertFalse($boundary->isActive());
  }

  public function test_nested_run_is_rejected_by_default_and_leaves_the_outer_transaction_untouched(): void {
    $boundary = new DbalTransactionBoundary($this->db);

    $this->db->beginTransaction();
    $this->db->insert('sf_tx_rows', ['id' => 'outer']);
    $ran = false;
    try {
      $boundary->run(function () use (&$ran) {
        $ran = true;
      });
      self::fail('expected NestedTransactionRejected');
    } catch (NestedTransactionRejected) {
    }

    self::assertFalse($ran, 'work must not run');
    self::assertTrue($this->db->isTransactionActive(), 'outer transaction still open');
    $this->db->commit();
    self::assertSame(['outer'], $this->db->fetchFirstColumn('SELECT id FROM sf_tx_rows'));
  }

  public function test_savepoint_policy_rolls_back_only_the_inner_work(): void {
    $boundary = new DbalTransactionBoundary($this->db, NestedPolicy::Savepoint);

    $boundary->run(function () use ($boundary) {
      $this->db->insert('sf_tx_rows', ['id' => 'outer']);
      try {
        $boundary->run(function () {
          $this->db->insert('sf_tx_rows', ['id' => 'inner']);
          throw new \RuntimeException('inner fails');
        });
      } catch (\RuntimeException) {
      }
    });

    self::assertSame(['outer'], $this->db->fetchFirstColumn('SELECT id FROM sf_tx_rows'));
  }

  public function test_a_failed_commit_throws_transaction_failed_and_persists_nothing(): void {
    $boundary = new DbalTransactionBoundary($this->db);

    try {
      $boundary->run(function () {
        $this->db->insert('sf_tx_rows', ['id' => 'a']);
        $this->db->insert('sf_tx_deferred', ['id' => 1, 'parent' => 999]); // violates at COMMIT
      });
      self::fail('expected TransactionFailed');
    } catch (TransactionFailed $e) {
      self::assertNotNull($e->getPrevious(), 'previous = driver error');
    }

    self::assertFalse($this->db->isTransactionActive());
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM sf_tx_rows'));
    // the connection is usable afterwards
    $boundary->run(fn () => $this->db->insert('sf_tx_rows', ['id' => 'b']));
    self::assertSame(['b'], $this->db->fetchFirstColumn('SELECT id FROM sf_tx_rows'));
  }

  /**
   * Postgres aborts the transaction on any statement error (25P02). If the
   * work swallows that error, COMMIT answers with the ROLLBACK tag and no
   * error, so DBAL's commit() does not throw. The boundary must still report
   * failure (register 3.2 / C13).
   */
  public function test_work_that_swallows_a_statement_error_is_a_transaction_failure_and_persists_nothing(): void {
    $this->db->insert('sf_tx_rows', ['id' => 'dup']);
    $boundary = new DbalTransactionBoundary($this->db);

    try {
      $result = $boundary->run(function () {
        $this->db->insert('sf_tx_rows', ['id' => 'a']);
        try {
          $this->db->insert('sf_tx_rows', ['id' => 'dup']);
        } catch (UniqueConstraintViolationException) {
          // carry on: the classic "insert, catch the duplicate" pattern
        }
        return 'ok';
      });
      self::fail('expected TransactionFailed, got ' . var_export($result, true));
    } catch (TransactionFailed $e) {
      self::assertNotNull($e->getPrevious(), 'previous = the aborted-transaction driver error');
    }

    self::assertFalse($this->db->isTransactionActive());
    self::assertSame(['dup'], $this->secondConnection()->fetchFirstColumn('SELECT id FROM sf_tx_rows'));
    // the connection is usable afterwards
    $boundary->run(fn () => $this->db->insert('sf_tx_rows', ['id' => 'b']));
    self::assertSame(['b', 'dup'], $this->db->fetchFirstColumn('SELECT id FROM sf_tx_rows ORDER BY id'));
  }

  /**
   * The same swallowed error, but a LATER statement of the work (the outbox
   * append of the command's fact, say) then fails with "current transaction
   * is aborted" (25P02) and the work throws that. The failure is the
   * aborted transaction, not the later statement: TransactionFailed, with
   * the work's exception as previous (CR sf-7, cmd.commit-failure).
   */
  public function test_work_failing_on_the_aborted_transaction_it_caused_is_a_transaction_failure(): void {
    $this->db->insert('sf_tx_rows', ['id' => 'dup']);
    $boundary = new DbalTransactionBoundary($this->db);
    $later = null;

    try {
      $boundary->run(function () use (&$later) {
        $this->db->insert('sf_tx_rows', ['id' => 'a']);
        try {
          $this->db->insert('sf_tx_rows', ['id' => 'dup']);
        } catch (UniqueConstraintViolationException) {
        }
        try {
          $this->db->insert('sf_tx_rows', ['id' => 'b']);
        } catch (\Throwable $e) {
          $later = new \RuntimeException('outbox write failed: ' . $e->getMessage(), 0, $e);
          throw $later;
        }
      });
      self::fail('expected TransactionFailed');
    } catch (TransactionFailed $e) {
      self::assertSame($later, $e->getPrevious(), 'previous = what the work threw');
      self::assertStringContainsString('aborted', $e->getMessage());
    }

    self::assertFalse($this->db->isTransactionActive());
    self::assertSame(['dup'], $this->secondConnection()->fetchFirstColumn('SELECT id FROM sf_tx_rows'));
  }

  public function test_a_work_exception_unrelated_to_an_aborted_transaction_is_rethrown_unchanged(): void {
    $boundary = new DbalTransactionBoundary($this->db);
    $boom = new \DomainException('business rule');

    try {
      $boundary->run(function () use ($boom): void {
        $this->db->insert('sf_tx_rows', ['id' => 'a']);
        throw $boom;
      });
      self::fail('expected the original');
    } catch (\DomainException $e) {
      self::assertSame($boom, $e);
    }
  }

  public function test_savepoint_mode_rolls_back_to_its_savepoint_when_the_inner_work_swallowed_an_error(): void {
    $this->db->insert('sf_tx_rows', ['id' => 'dup']);
    $boundary = new DbalTransactionBoundary($this->db, NestedPolicy::Savepoint);

    $this->db->beginTransaction();
    $this->db->insert('sf_tx_rows', ['id' => 'outer']);
    try {
      $boundary->run(function () {
        $this->db->insert('sf_tx_rows', ['id' => 'inner']);
        try {
          $this->db->insert('sf_tx_rows', ['id' => 'dup']);
        } catch (UniqueConstraintViolationException) {
        }
      });
      self::fail('expected TransactionFailed');
    } catch (TransactionFailed) {
    }

    self::assertSame(1, $this->db->getTransactionNestingLevel(), 'the outer transaction is not ours to end');
    // ROLLBACK TO SAVEPOINT recovered the outer transaction: it is usable and commits its own row
    $this->db->insert('sf_tx_rows', ['id' => 'outer2']);
    $this->db->commit();
    self::assertSame(['dup', 'outer', 'outer2'], $this->db->fetchFirstColumn('SELECT id FROM sf_tx_rows ORDER BY id'));
  }

  public function test_before_commit_hook_runs_inside_the_transaction_and_its_failure_rolls_back(): void {
    $flushedInside = null;
    $boundary = new DbalTransactionBoundary($this->db, beforeCommit: function () use (&$flushedInside) {
      $flushedInside = $this->db->isTransactionActive();
      $this->db->insert('sf_tx_rows', ['id' => 'flushed']);
    });

    $boundary->run(fn () => $this->db->insert('sf_tx_rows', ['id' => 'work']));
    self::assertTrue($flushedInside);
    self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM sf_tx_rows'));

    $failing = new DbalTransactionBoundary($this->db, beforeCommit: static function (): void {
      throw new \LogicException('flush failed');
    });
    try {
      $failing->run(fn () => $this->db->insert('sf_tx_rows', ['id' => 'never']));
      self::fail('expected the flush failure');
    } catch (\LogicException $e) {
      self::assertSame('flush failed', $e->getMessage());
    }
    self::assertSame(2, (int) $this->db->fetchOne('SELECT count(*) FROM sf_tx_rows'));
  }
}
