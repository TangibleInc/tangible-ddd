<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8;

use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\LongProcessCatalog;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Tests\Integration\V8\Fakes\V8Fact;
use TangibleDDD\Tests\Integration\V8\Fakes\V8IgnitedProcess;
use TangibleDDD\WordPress\Adapter\WpSchema;

use function TangibleDDD\WordPress\ddd_maybe_migrate;
use function TangibleDDD\WordPress\outbox_enabled;
use function TangibleDDD\WordPress\processes_enabled;
use function TangibleDDD\WordPress\register_hooks;

use const TangibleDDD\WordPress\TABLES_INSTALLED_ACTION;

/**
 * The first request on a fresh database (cred consumer triage, wave 5):
 * register_hooks() runs at init:2 and probes the process and outbox tables
 * before the migration trigger creates them at init:3. It used to cache
 * "absent" for the request and wire no #[StartsOn] / #[Awaits] hook, so the
 * first fact of a fresh install ignited nothing. Now ddd_maybe_migrate()
 * forgets the probes and announces the installed tables, and register_hooks()
 * wires what it skipped, in the same request.
 */
final class FreshDatabaseWiringTest extends V8TestCase {

  protected function setUp(): void {
    parent::setUp();
    WpSchema::forget_tables(); // earlier tests probed this prefix's tables before they were dropped
    remove_all_actions(TABLES_INSTALLED_ACTION);
    V8IgnitedProcess::$runs = 0;
  }

  protected function tearDown(): void {
    remove_all_actions(TABLES_INSTALLED_ACTION);
    WpSchema::forget_tables();
    parent::tearDown();
  }

  /** A compiled consumer container: the process catalog and a runner built on first use (as Symfony DI does). */
  private function container(): object {
    return new class($this->config) {
      private ?ProcessRunner $runner = null;

      public function __construct(private readonly \TangibleDDD\Infra\IDDDConfig $config) {}

      public function has(string $id): bool {
        return in_array($id, [LongProcessCatalog::class, ProcessRunner::class], true);
      }

      public function get(string $id): object {
        return match ($id) {
          LongProcessCatalog::class => new LongProcessCatalog([V8IgnitedProcess::class => []]),
          ProcessRunner::class => $this->runner ??= new ProcessRunner($this->config, new ProcessRepository($this->config)),
        };
      }
    };
  }

  public function test_a_fresh_database_ignites_a_process_in_the_first_request(): void {
    $container = $this->container();
    self::assertFalse($this->tableExists($this->table('long_processes')), 'an empty database');

    // init:2 — the consumer's hooks, before its tables exist.
    register_hooks($this->config, static fn () => $container);
    self::assertFalse(processes_enabled($this->config), 'probed absent');
    self::assertFalse(has_action(V8Fact::integration_action()), 'nothing wired yet');

    // init:3 — the migration trigger creates the tables.
    ddd_maybe_migrate($this->config);

    self::assertTrue(processes_enabled($this->config), 'the probe was forgotten');
    self::assertTrue(outbox_enabled($this->config));
    self::assertNotFalse(has_action(V8Fact::integration_action()), 'the #[StartsOn] ignition is wired in the same request');

    // The first fact of the install ignites its process.
    do_action(V8Fact::integration_action(), IntegrationEnvelope::wrap((new V8Fact(3))->integration_payload(), '44444444-4444-4444-8444-444444444443', 1, 'f0000000-0000-4000-8000-000000000003'));

    self::assertSame(1, V8IgnitedProcess::$runs);
    self::assertSame(1, (int) $this->wpdb->get_var("SELECT COUNT(*) FROM `{$this->table('long_processes')}` WHERE process_class = '" . esc_sql(V8IgnitedProcess::class) . "'"));
  }

  public function test_hooks_are_wired_once_when_the_tables_are_announced_again(): void {
    $container = $this->container();
    register_hooks($this->config, static fn () => $container);
    ddd_maybe_migrate($this->config);
    $wired = $this->callbacks(V8Fact::integration_action());

    do_action(TABLES_INSTALLED_ACTION, $this->config); // e.g. a later admin_init heal

    self::assertSame($wired, $this->callbacks(V8Fact::integration_action()));
  }

  public function test_another_consumers_tables_wire_nothing_here(): void {
    $container = $this->container();
    register_hooks($this->config, static fn () => $container);

    do_action(TABLES_INSTALLED_ACTION, new \TangibleDDD\Infra\DDDConfig('ddd8other', 'Other\\Ns', '1.0.0'));

    self::assertFalse(has_action(V8Fact::integration_action()));
  }

  public function test_installed_tables_wire_at_once(): void {
    $this->installCurrent();
    $container = $this->container();

    register_hooks($this->config, static fn () => $container);

    self::assertNotFalse(has_action(V8Fact::integration_action()), 'the usual path: tables present at init:2');
    self::assertFalse(has_action(TABLES_INSTALLED_ACTION), 'nothing left to wire later');
  }

  private function callbacks(string $hook): int {
    global $wp_filter;
    $count = 0;
    foreach (($wp_filter[$hook] ?? null)?->callbacks ?? [] as $byPriority) {
      $count += count($byPriority);
    }
    return $count;
  }
}
