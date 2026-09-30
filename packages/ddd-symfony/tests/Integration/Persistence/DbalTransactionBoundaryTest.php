<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

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
