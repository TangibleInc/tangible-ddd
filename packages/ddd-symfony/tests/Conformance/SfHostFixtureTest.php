<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Conformance\ScenarioContext;
use TangibleDDD\Runtime\TransactionFailed;
use TangibleDDD\Symfony\Tests\Support\PostgresDatabase;

/**
 * The sf fixture's own guarantees, so a green scenario cannot be a vacuous
 * one: a fresh schema per test that is dropped afterwards, a COMMIT failure
 * raised by Postgres itself, and the shared-connection relay hand-off.
 */
#[Group('sf')]
#[Group('conformance')]
final class SfHostFixtureTest extends TestCase {

  private function fixture(string $method): array {
    $context = new ScenarioContext(self::class, $method, null);
    $fixture = new SfHostFixture();
    $fixture->setUp($context);
    return [$fixture, $context->uniqueName('sf')];
  }

  public function test_each_test_gets_its_own_schema_and_it_is_dropped_afterwards(): void {
    [$a, $schemaA] = $this->fixture('a');
    [$b, $schemaB] = $this->fixture('b');
    $admin = PostgresDatabase::connect();
    try {
      self::assertNotSame($schemaA, $schemaB);
      $a->scenarioRows()->insert('only-in-a', 'x');
      self::assertTrue($a->scenarioRows()->has('only-in-a'));
      self::assertFalse($b->scenarioRows()->has('only-in-a'), 'schemas are isolated');
      $tables = $admin->fetchFirstColumn('SELECT table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY 1', [$schemaA]);
      foreach (['ddd_outbox', 'ddd_dlq', 'ddd_relay_pauses', 'ddd_delivery_ledger', 'messenger_messages', 'conf_scenario_rows'] as $t) {
        self::assertContains($t, $tables);
      }

      $a->tearDown();
      $b->tearDown();

      self::assertFalse($admin->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schemaA]), 'dropped on tearDown');
      self::assertFalse($admin->fetchOne('SELECT 1 FROM pg_namespace WHERE nspname = ?', [$schemaB]));
    } finally {
      $a->tearDown();
      $b->tearDown();
      $admin->close();
    }
  }

  public function test_an_injected_commit_failure_is_raised_by_postgres_at_commit(): void {
    [$host] = $this->fixture('commit');
    try {
      $host->failNextCommit('injected');
      $thrown = null;
      try {
        $host->boundary()->run(static fn () => $host->scenarioRows()->insert('w', 'v'));
      } catch (TransactionFailed $e) {
        $thrown = $e;
      }

      self::assertNotNull($thrown);
      self::assertStringStartsWith('COMMIT failed', $thrown->getMessage());
      self::assertStringContainsString('23503', $thrown->getPrevious()?->getMessage() ?? '', 'a deferred FK violation reported at COMMIT');
      self::assertSame(0, $host->scenarioRows()->count());
      self::assertFalse($host->boundary()->isActive());

      $host->boundary()->run(static fn () => $host->scenarioRows()->insert('w', 'v'));
      self::assertSame(1, $host->scenarioRows()->count(), 'one-shot: the next commit succeeds');
    } finally {
      $host->tearDown();
    }
  }

  public function test_the_relay_hand_off_is_the_shared_connection_one(): void {
    [$host] = $this->fixture('shared');
    try {
      self::assertTrue($host->transport()->sharesConnectionWith($host->outbox()));
    } finally {
      $host->tearDown();
    }
  }
}
