<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Unit\Persistence;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Symfony\Persistence\TableNames;

final class TableNamesTest extends TestCase {

  public function test_a_plain_prefix_names_tables_in_the_search_path(): void {
    $names = TableNames::of('b_');

    self::assertSame('b_ddd_outbox', $names->table('ddd_outbox'));
    self::assertNull($names->schema());
    self::assertSame('b_', $names->prefix());
  }

  public function test_a_schema_qualifies_every_table(): void {
    $names = TableNames::of('billing.b_');

    self::assertSame('billing.b_ddd_outbox', $names->table('ddd_outbox'));
    self::assertSame('billing', $names->schema());
    self::assertSame('b_', $names->prefix());
    self::assertSame('billing.ddd_wakeups', TableNames::of('billing.')->table('ddd_wakeups'));
  }

  public function test_join_builds_the_qualified_string(): void {
    self::assertSame('billing.b_', TableNames::join('billing', 'b_'));
    self::assertSame('b_', TableNames::join(null, 'b_'));
    self::assertSame('', TableNames::join('', ''));
  }

  /** @return iterable<string, array{string}> */
  public static function invalid(): iterable {
    yield 'quote in prefix' => ["b'; --"];
    yield 'upper-case schema' => ['Billing.x_'];
    yield 'two dots' => ['a.b.c'];
    yield 'empty schema' => ['.x_'];
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
  public function test_rejects_anything_that_is_not_an_identifier(string $qualified): void {
    $this->expectException(\InvalidArgumentException::class);
    TableNames::of($qualified);
  }
}
