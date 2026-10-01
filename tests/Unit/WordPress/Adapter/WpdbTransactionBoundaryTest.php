<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress\Adapter;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\TransactionFailed;
use TangibleDDD\WordPress\Adapter\UncheckedWpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpdbTransactionDepth;

/** Register 3.2 wp form: every wpdb result checked; nesting counted per request. */
final class WpdbTransactionBoundaryTest extends TestCase {

  protected function setUp(): void {
    WpdbTransactionDepth::reset_for_tests();
  }

  /** @param array<string, false> $fail SQL => false */
  private function db(array $fail = []): \wpdb {
    return new class($fail) extends \wpdb {
      /** @var list<string> */
      public array $queries = [];
      public function __construct(private array $fail) {}
      public function query(string $query) {
        $this->queries[] = $query;
        if (array_key_exists($query, $this->fail)) {
          $this->last_error = "error on $query";
          return false;
        }
        return true;
      }
    };
  }

  public function test_commits_and_returns_the_work_value(): void {
    $db = $this->db();
    $boundary = new WpdbTransactionBoundary(wpdb: $db);

    self::assertInstanceOf(ITransactionBoundary::class, $boundary);
    self::assertSame(42, $boundary->run(function () use ($boundary) {
      self::assertTrue($boundary->is_active());
      return 42;
    }));
    self::assertSame(['START TRANSACTION', 'COMMIT'], $db->queries);
    self::assertFalse($boundary->is_active());
  }

  public function test_a_throw_rolls_back_and_rethrows_the_original(): void {
    $db = $this->db(['ROLLBACK' => false]);

    try {
      (new WpdbTransactionBoundary(wpdb: $db))->run(static fn () => throw new \DomainException('business'));
      self::fail('expected the original exception');
    } catch (\DomainException $e) {
      self::assertSame('business', $e->getMessage(), 'a failed rollback never replaces the original');
    }
    self::assertSame(['START TRANSACTION', 'ROLLBACK'], $db->queries);
  }

  public function test_a_failed_begin_throws_before_the_work_runs(): void {
    $ran = false;
    $this->expectException(TransactionFailed::class);
    $this->expectExceptionMessage('error on START TRANSACTION');
    try {
      (new WpdbTransactionBoundary(wpdb: $this->db(['START TRANSACTION' => false])))->run(function () use (&$ran) { $ran = true; });
    } finally {
      self::assertFalse($ran);
    }
  }

  public function test_a_failed_commit_throws_transaction_failed(): void {
    $db = $this->db(['COMMIT' => false]);
    try {
      (new WpdbTransactionBoundary(wpdb: $db))->run(static fn () => 'x');
      self::fail('a failed COMMIT must surface');
    } catch (TransactionFailed $e) {
      self::assertStringContainsString('error on COMMIT', $e->getMessage());
    }
    self::assertSame(['START TRANSACTION', 'COMMIT', 'ROLLBACK'], $db->queries);
  }

  public function test_nested_run_is_rejected_by_default(): void {
    $db = $this->db();
    $boundary = new WpdbTransactionBoundary(wpdb: $db);

    $this->expectException(NestedTransactionRejected::class);
    $boundary->run(static fn () => $boundary->run(static fn () => null));
  }

  public function test_savepoint_policy_nests_with_savepoints(): void {
    $db = $this->db();
    $boundary = new WpdbTransactionBoundary(NestedPolicy::Savepoint, $db);

    $boundary->run(static function () use ($boundary): void {
      try {
        $boundary->run(static fn () => throw new \RuntimeException('inner'));
      } catch (\RuntimeException) {
      }
      $boundary->run(static fn () => null);
    });

    self::assertSame([
      'START TRANSACTION',
      'SAVEPOINT ddd_sp_1', 'ROLLBACK TO SAVEPOINT ddd_sp_1',
      'SAVEPOINT ddd_sp_2', 'RELEASE SAVEPOINT ddd_sp_2',
      'COMMIT',
    ], $db->queries);
  }

  public function test_a_legacy_command_transaction_counts_as_open(): void {
    $db = $this->db();
    $legacy = new UncheckedWpdbTransactionBoundary($db);
    $checked = new WpdbTransactionBoundary(wpdb: $db);

    $legacy->run(static function () use ($checked): void {
      self::assertTrue($checked->is_active(), 'the shared per-request depth sees the 0.6 transaction');
    });
    self::assertFalse($checked->is_active());
  }

  public function test_reads_the_global_wpdb_per_call(): void {
    $GLOBALS['wpdb'] = $db = $this->db();
    (new WpdbTransactionBoundary())->run(static fn () => null);
    self::assertSame(['START TRANSACTION', 'COMMIT'], $db->queries);
  }
}
