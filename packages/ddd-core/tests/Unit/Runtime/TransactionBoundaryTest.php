<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\NestedTransactionRejected;
use TangibleDDD\Runtime\NoTransactionBoundary;
use TangibleDDD\Runtime\TransactionFailed;
use TangibleDDD\Testing\InMemoryTransactionBoundary;
use TangibleDDD\Testing\InMemoryTransactional;

final class TransactionBoundaryTest extends TestCase {

  protected function tearDown(): void {
    HostDefaults::resetForTests();
  }

  private function table(): InMemoryTransactional {
    return new class implements InMemoryTransactional {
      public array $rows = [];
      public function snapshotState(): mixed { return $this->rows; }
      public function restoreState(mixed $state): void { $this->rows = $state; }
    };
  }

  public function test_run_commits_and_returns_the_work_value(): void {
    $tx = new InMemoryTransactionBoundary();
    $table = $this->table();
    $tx->enlist($table);

    self::assertInstanceOf(ITransactionBoundary::class, $tx);
    self::assertFalse($tx->isActive());

    $result = $tx->run(function () use ($tx, $table) {
      self::assertTrue($tx->isActive());
      $table->rows[] = 'domain';
      return 'dto';
    });

    self::assertSame('dto', $result);
    self::assertSame(['domain'], $table->rows);
    self::assertFalse($tx->isActive());
    self::assertSame(1, $tx->commits());
  }

  public function test_a_throwing_work_rolls_back_and_rethrows_the_original(): void {
    $tx = new InMemoryTransactionBoundary();
    $table = $this->table();
    $tx->enlist($table);
    $original = new \DomainException('reaction failed');

    try {
      $tx->run(function () use ($table, $original) {
        $table->rows[] = 'domain';
        throw $original;
      });
      self::fail('expected the original exception');
    } catch (\DomainException $e) {
      self::assertSame($original, $e);
    }

    self::assertSame([], $table->rows);
    self::assertSame(1, $tx->rollbacks());
    self::assertFalse($tx->isActive());
  }

  public function test_a_failed_rollback_is_logged_as_a_secondary_and_never_replaces_the_original(): void {
    $logged = [];
    $tx = new InMemoryTransactionBoundary(NestedPolicy::Reject, static function (string $m) use (&$logged) { $logged[] = $m; });
    $tx->failNextRollback('connection lost');
    $original = new \DomainException('handler');

    try {
      $tx->run(static function () use ($original) { throw $original; });
      self::fail('expected the original exception');
    } catch (\DomainException $e) {
      self::assertSame($original, $e);
    }
    self::assertCount(1, $logged);
    self::assertStringContainsString('connection lost', $logged[0]);
  }

  public function test_a_failed_commit_throws_transaction_failed_and_persists_nothing(): void {
    $tx = new InMemoryTransactionBoundary();
    $table = $this->table();
    $tx->enlist($table);
    $tx->failNextCommit('deadlock');

    try {
      $tx->run(function () use ($table) { $table->rows[] = 'domain'; return 1; });
      self::fail('expected TransactionFailed');
    } catch (TransactionFailed $e) {
      self::assertStringContainsString('deadlock', $e->getMessage());
      self::assertNotNull($e->getPrevious());
    }
    self::assertSame([], $table->rows);
    self::assertFalse($tx->isActive());
  }

  public function test_nested_run_is_rejected_by_default_and_the_outer_tx_is_untouched(): void {
    $tx = new InMemoryTransactionBoundary();
    $table = $this->table();
    $tx->enlist($table);

    $tx->run(function () use ($tx, $table) {
      $table->rows[] = 'outer';
      try {
        $tx->run(static fn () => null);
        self::fail('expected NestedTransactionRejected');
      } catch (NestedTransactionRejected) {
      }
      self::assertTrue($tx->isActive());
    });

    self::assertSame(['outer'], $table->rows);
    self::assertSame(1, $tx->commits());
  }

  public function test_savepoint_policy_rolls_back_only_the_inner_work(): void {
    $tx = new InMemoryTransactionBoundary(NestedPolicy::Savepoint);
    $table = $this->table();
    $tx->enlist($table);

    $tx->run(function () use ($tx, $table) {
      $table->rows[] = 'outer';
      try {
        $tx->run(function () use ($table) {
          $table->rows[] = 'inner';
          throw new \RuntimeException('inner');
        });
      } catch (\RuntimeException) {
      }
      $tx->run(function () use ($table) { $table->rows[] = 'inner2'; });
    });

    self::assertSame(['outer', 'inner2'], $table->rows);
    self::assertSame(1, $tx->commits());
  }

  public function test_middleware_passes_non_transactional_commands_straight_through(): void {
    $mw = new TransactionalCommandMiddleware(null);
    $cmd = new \stdClass();

    self::assertSame('ok', $mw->execute($cmd, static fn ($c) => 'ok'));
  }

  public function test_middleware_throws_no_boundary_before_the_handler_runs(): void {
    $mw = new TransactionalCommandMiddleware();
    $ran = false;

    try {
      $mw->execute(new class implements ITransactionalCommand {}, function () use (&$ran) { $ran = true; });
      self::fail('expected NoTransactionBoundary');
    } catch (NoTransactionBoundary) {
    }
    self::assertFalse($ran);
  }

  public function test_middleware_wraps_transactional_commands_and_returns_the_value(): void {
    $tx = new InMemoryTransactionBoundary();
    $mw = new TransactionalCommandMiddleware($tx);

    $value = $mw->execute(new class implements ITransactionalCommand {}, function () use ($tx) {
      self::assertTrue($tx->isActive());
      return ['receipt' => 42];
    });

    self::assertSame(['receipt' => 42], $value);
    self::assertSame(1, $tx->commits());
  }

  public function test_middleware_resolves_a_null_boundary_from_host_defaults(): void {
    $tx = new InMemoryTransactionBoundary();
    HostDefaults::provide(ITransactionBoundary::class, $tx);
    $mw = new TransactionalCommandMiddleware();

    $mw->execute(new class implements ITransactionalCommand {}, static fn () => null);

    self::assertSame(1, $tx->commits());
  }
}
