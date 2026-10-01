<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress\Adapter;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Runtime\Audit\ActorKind;
use TangibleDDD\WordPress\Adapter\WpActorProvider;
use TangibleDDD\WordPress\Adapter\WpEnvironmentProvider;

/**
 * The wp audit path of the core act bracket writes the 0.6 rows: the
 * CorrelationMiddleware 0.6.5 constructor, resolved through HostDefaults
 * (WpHostPortFactory → WpdbAuditSink, WpActorProvider,
 * WpEnvironmentProvider), into `{prefix}_command_audit`.
 */
final class WpAuditAdaptersTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  private function wpdb(bool $table_exists): \wpdb {
    return new class($table_exists) extends \wpdb {
      public array $inserts = [];
      public array $updates = [];
      private string $last = '';
      public function __construct(private bool $exists) {}
      public function prepare(string $query, ...$args): string {
        $this->last = (string) ($args[0] ?? '');
        return $query;
      }
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        return $query !== null && str_starts_with($query, 'SHOW TABLES') && $this->exists ? $this->last : null;
      }
      public function insert(string $table, array $data, $format = null): bool {
        $this->inserts[] = [$table, $data];
        return true;
      }
      public function update(string $table, array $data, array $where, $format = null, $where_format = null): bool {
        $this->updates[] = [$table, $data, $where];
        return true;
      }
    };
  }

  private function bracket(string $prefix): CorrelationMiddleware {
    return new CorrelationMiddleware(
      new DDDConfig(prefix: $prefix, namespace_root: 'Acme\\Audit', version: '3.4.5'),
      new EventsUnitOfWork(),
      new Redactor(),
    );
  }

  public function test_the_0_6_constructor_writes_the_0_6_audit_row(): void {
    $GLOBALS['wpdb'] = $db = $this->wpdb(true);
    $command = new class { public int $order_id = 9; public string $api_key = 'sekret'; };

    Correlation::within((new TraceContext('story-7'))->for_fact('evt-3', 'X'), fn () =>
      $this->bracket('auditwp1')->execute($command, static fn () => 'done'));

    [$table, $row] = $db->inserts[0];
    self::assertSame('wp_auditwp1_command_audit', $table);
    self::assertSame('in_progress', $row['status']);
    self::assertSame('story-7', $row['correlation_id']);
    self::assertSame('evt-3', $row['causation_id']);
    self::assertSame('integration_event', $row['causation_type']);
    self::assertSame('cli', $row['source'], 'the unit suite runs under the CLI SAPI');
    self::assertSame('', $row['source_id']);
    self::assertSame(1, $row['blog_id']);
    self::assertSame(['php' => PHP_VERSION, 'wp' => 'test', 'plugin' => '3.4.5'], json_decode($row['environment'], true));
    $params = json_decode($row['parameters'], true);
    self::assertSame(9, $params['order_id']);
    self::assertNotSame('sekret', $params['api_key']);

    [$utable, $update, $where] = $db->updates[0];
    self::assertSame('wp_auditwp1_command_audit', $utable);
    self::assertSame('success', $update['status']);
    self::assertSame(['command_id' => $row['command_id']], $where);
  }

  public function test_without_the_audit_table_nothing_is_written_and_the_guard_still_holds(): void {
    $GLOBALS['wpdb'] = $db = $this->wpdb(false);

    $this->bracket('auditwp2')->execute(new \stdClass(), static fn () => null);
    self::assertSame([], $db->inserts);

    $this->expectException(\TangibleDDD\Application\Exceptions\CommandDispatchedInsideCommand::class);
    Correlation::within((new TraceContext('c'))->for_act('outer'), fn () =>
      $this->bracket('auditwp2')->execute(new \stdClass(), static fn () => null));
  }

  public function test_actor_and_environment_providers(): void {
    $actor = (new WpActorProvider())->current();
    self::assertSame(ActorKind::Cli, $actor->kind);

    self::assertSame(['php' => PHP_VERSION, 'wp' => 'test'], (new WpEnvironmentProvider())->describe());
  }
}
