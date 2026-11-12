<?php

namespace TangibleDDD\Tests\Unit\Intents;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\IDDDConfig;

use function TangibleDDD\WordPress\command_audit_finalise;
use function TangibleDDD\WordPress\command_audit_has_intents_column;
use function TangibleDDD\WordPress\ddd_column_exists;

/**
 * command_audit_finalise() writes the act's intents only when there are some
 * AND the column exists (a consumer not yet migrated to schema v8 must keep
 * finalising its audit row). The column check is cached per prefix, so every
 * test uses its own.
 */
class AuditIntentsTest extends TestCase {

  /** @var array<int, array> */
  private array $updates = [];

  protected function setUp(): void {
    $this->updates = [];
  }

  private function config(string $prefix): IDDDConfig {
    return new class($prefix) implements IDDDConfig {
      public function __construct(private string $p) {}
      public function prefix(): string { return $this->p; }
      public function table(string $name): string { return 'wp_' . $this->p . '_' . $name; }
      public function hook(string $name): string { return $this->p . '_' . $name; }
      public function as_group(string $name): string { return $this->p . '-' . $name; }
      public function option(string $name): string { return $this->p . '_' . $name; }
      public function domain_action(string $e): string { return $this->p . '_domain_' . $e; }
      public function integration_action(string $e): string { return $this->p . '_integration_' . $e; }
      public function version(): string { return $this->p; }
    };
  }

  private int $column_queries = 0;

  private function wpdb(bool $has_column): void {
    $this->column_queries = 0;
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('prepare')->willReturnArgument(0);
    $wpdb->method('get_var')->willReturnCallback(function(string $sql) use ($has_column) {
      if( !str_contains($sql, 'information_schema.COLUMNS') ) {
        return null;
      }
      $this->column_queries++;
      return $has_column ? 1 : 0;
    });
    $wpdb->method('update')->willReturnCallback(function($table, $row) {
      $this->updates[] = $row;
      return true;
    });
    $GLOBALS['wpdb'] = $wpdb;
  }

  private function finalise(string $prefix, array $extra): void {
    command_audit_finalise($this->config($prefix), ['command_id' => 'abc', 'status' => 'success'] + $extra);
  }

  private const INTENTS = [[
    'command'  => 'Some\\Command',
    'origin'   => 'file.php:1',
    'decision' => 'rejected',
    'reason'   => 'mine',
  ]];

  public function test_finalise_writes_intents_when_present(): void {
    $this->wpdb(true);
    $this->finalise('aud1', ['intents' => self::INTENTS]);

    $this->assertCount(1, $this->updates);
    $this->assertSame(self::INTENTS, json_decode($this->updates[0]['intents'], true));
  }

  public function test_finalise_omits_intents_when_empty(): void {
    $this->wpdb(true);
    $this->finalise('aud2', ['intents' => []]);

    $this->assertCount(1, $this->updates);
    $this->assertArrayNotHasKey('intents', $this->updates[0]);
  }

  public function test_finalise_omits_intents_when_the_column_is_missing(): void {
    $this->wpdb(false);
    $this->finalise('aud3', ['intents' => self::INTENTS]);

    $this->assertCount(1, $this->updates);
    $this->assertArrayNotHasKey('intents', $this->updates[0]);
    $this->assertSame('success', $this->updates[0]['status']);
  }

  public function test_present_column_is_cached_per_prefix(): void {
    $this->wpdb(true);

    $this->assertTrue(command_audit_has_intents_column($this->config('aud4')));
    $this->assertTrue(command_audit_has_intents_column($this->config('aud4')));

    $this->assertSame(1, $this->column_queries, 'the second call does not re-query');
  }

  public function test_missing_column_is_not_cached(): void {
    $this->wpdb(false);

    $this->assertFalse(command_audit_has_intents_column($this->config('aud5')));
    $this->assertFalse(command_audit_has_intents_column($this->config('aud5')));

    $this->assertSame(2, $this->column_queries, 'a worker that outlives the migration must see the column');
  }

  public function test_column_exists_reads_information_schema(): void {
    $this->wpdb(true);
    $this->assertTrue(ddd_column_exists('wp_x_command_audit', 'intents'));

    $this->wpdb(false);
    $this->assertFalse(ddd_column_exists('wp_x_command_audit', 'intents'));
  }
}
