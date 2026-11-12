<?php

namespace TangibleDDD\Tests\Unit\Intents;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Infra\IDDDConfig;

use function TangibleDDD\WordPress\command_audit_finalise;

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

  private function wpdb(bool $has_column): void {
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('prepare')->willReturnArgument(0);
    $wpdb->method('get_var')->willReturnCallback(
      fn(string $sql) => str_contains($sql, 'information_schema.COLUMNS') ? ($has_column ? 1 : 0) : null
    );
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
}
