<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Core\Tests\Pdo\Support\FaultyConnection;
use TangibleDDD\Core\Tests\Unit\Fixtures\RecordingLogger;
use TangibleDDD\Defaults\Pdo\PdoConfigurationError;
use TangibleDDD\Defaults\Pdo\PdoConnection;
use TangibleDDD\Defaults\Pdo\PdoTransactionBoundary;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\TransactionFailed;

abstract class PdoTransactionBoundaryCases extends PdoTestCase {

  private function insert(string $name): void {
    $this->db->execute('INSERT INTO tp_widgets (name) VALUES (?)', [$name]);
  }

  /** @return list<string> */
  private function names(): array {
    return array_column($this->db->fetch_all('SELECT name FROM tp_widgets ORDER BY id'), 'name');
  }

  public function test_run_commits_and_returns_the_value_unchanged(): void {
    $boundary = new PdoTransactionBoundary($this->db);
    self::assertInstanceOf(ITransactionBoundary::class, $boundary);
    $dto = new \stdClass();

    $result = $boundary->run(function () use ($boundary, $dto) {
      self::assertTrue($boundary->is_active());
      $this->insert('a');
      return $dto;
    });

    self::assertSame($dto, $result);
    self::assertFalse($boundary->is_active());
    self::assertSame(['a'], $this->names());
    self::assertSame(['a'], array_column($this->otherConnection()->fetch_all('SELECT name FROM tp_widgets'), 'name'), 'visible to another connection: committed');
  }

  public function test_a_throwing_work_rolls_back_and_rethrows_the_original(): void {
    $boundary = new PdoTransactionBoundary($this->db);
    $original = new \DomainException('handler failed');

    try {
      $boundary->run(function () use ($original) {
        $this->insert('a');
        throw $original;
      });
      self::fail('expected the original exception');
    } catch (\DomainException $e) {
      self::assertSame($original, $e);
    }
    self::assertFalse($this->db->in_transaction());
    self::assertSame([], $this->names());
  }

  public function test_nested_run_is_rejected_by_default_and_the_outer_transaction_is_untouched(): void {
    $boundary = new PdoTransactionBoundary($this->db);

    $boundary->run(function () use ($boundary) {
      $this->insert('outer');
      try {
        $boundary->run(fn () => $this->insert('inner'));
        self::fail('expected NestedTransactionRejected');
      } catch (NestedTransactionRejected) {
      }
      self::assertTrue($this->db->in_transaction(), 'outer transaction still open');
      $this->insert('outer-after');
    });

    self::assertSame(['outer', 'outer-after'], $this->names());
  }

  public function test_reject_also_applies_to_a_transaction_the_host_opened_itself(): void {
    $boundary = new PdoTransactionBoundary($this->db);
    $this->db->begin();
    $this->insert('host');
    try {
      $boundary->run(fn () => $this->insert('inner'));
      self::fail('expected NestedTransactionRejected');
    } catch (NestedTransactionRejected) {
      self::assertTrue($this->db->in_transaction());
    } finally {
      $this->db->commit();
    }
    self::assertSame(['host'], $this->names());
  }

  public function test_savepoint_policy_rolls_back_only_the_inner_work(): void {
    $boundary = new PdoTransactionBoundary($this->db, NestedPolicy::Savepoint);

    $boundary->run(function () use ($boundary) {
      $this->insert('outer');
      try {
        $boundary->run(function () {
          $this->insert('inner-failed');
          throw new \RuntimeException('inner');
        });
      } catch (\RuntimeException) {
      }
      $value = $boundary->run(function () {
        $this->insert('inner-kept');
        return 42;
      });
      self::assertSame(42, $value);
      self::assertTrue($this->db->in_transaction());
    });

    self::assertSame(['outer', 'inner-kept'], $this->names());
  }

  public function test_a_failed_commit_throws_transaction_failed_and_commits_nothing(): void {
    $faulty = new FaultyConnection($this->db);
    $boundary = new PdoTransactionBoundary($faulty);
    $faulty->failOn = 'commit';

    try {
      $boundary->run(fn () => $this->insert('a'));
      self::fail('expected TransactionFailed');
    } catch (TransactionFailed $e) {
      self::assertSame('injected commit failure', $e->getPrevious()?->getMessage());
    }
    self::assertFalse($this->db->in_transaction(), 'the open transaction was rolled back');
    self::assertSame([], $this->names());
  }

  public function test_a_failed_begin_throws_transaction_failed_before_the_work_runs(): void {
    $faulty = new FaultyConnection($this->db);
    $boundary = new PdoTransactionBoundary($faulty);
    $faulty->failOn = 'begin';
    $ran = false;

    try {
      $boundary->run(function () use (&$ran) { $ran = true; });
      self::fail('expected TransactionFailed');
    } catch (TransactionFailed) {
    }
    self::assertFalse($ran);
  }

  public function test_a_rollback_failure_is_logged_and_never_replaces_the_original(): void {
    $faulty = new FaultyConnection($this->db);
    $logger = new RecordingLogger();
    $boundary = new PdoTransactionBoundary($faulty, logger: $logger);
    $faulty->failOn = 'rollback';
    $original = new \DomainException('handler failed');

    try {
      $boundary->run(function () use ($original) {
        $this->insert('a');
        throw $original;
      });
      self::fail('expected the original');
    } catch (\DomainException $e) {
      self::assertSame($original, $e);
    }
    self::assertCount(1, $logger->records);
    self::assertStringContainsString('injected rollback failure', $logger->messages()[0]);
    self::assertSame([], $this->names());
  }

  public function test_construction_refuses_a_connection_whose_errmode_was_switched_off(): void {
    $pdo = self::newPdo(static::emulatePrepares());
    $db = new PdoConnection($pdo);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);

    $this->expectException(PdoConfigurationError::class);
    new PdoTransactionBoundary($db);
  }
}
