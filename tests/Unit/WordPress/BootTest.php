<?php

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeGatherProcess;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\WordPress\ConsumerRegistry;

use function TangibleDDD\WordPress\boot;
use function TangibleDDD\WordPress\consumers;

// Procedural ddd-wordpress files; load directly (mirrors MigrationsTest).
if (!function_exists('TangibleDDD\\WordPress\\boot')) {
  require_once __DIR__ . '/../../../ddd-wordpress/hooks.php';
  require_once __DIR__ . '/../../../ddd-wordpress/tables.php';
  require_once __DIR__ . '/../../../ddd-wordpress/migrations.php';
  require_once __DIR__ . '/../../../ddd-wordpress/ConsumerHandle.php';
  require_once __DIR__ . '/../../../ddd-wordpress/ConsumerRegistry.php';
}

/**
 * boot() is the consumer's single wiring line: it defers register_hooks()
 * to init (after the consumer's container compiles on init:1) AND announces
 * the plugin to the consumer registry — the discovery surface for the ops
 * dashboard, WP-CLI, and cross-consumer tooling.
 */
class BootTest extends TestCase {

  protected function setUp(): void {
    global $_test_actions, $_test_filters;
    $_test_actions = [];
    $_test_filters = [];
    // The outbox/process feature gates probe table existence through $wpdb;
    // the stub's get_var(null) reads as "tables absent" → gates closed.
    $GLOBALS['wpdb'] = new \wpdb();
    ConsumerRegistry::reset();
  }

  public function test_boot_registers_the_consumer_immediately(): void {
    $config = new FakeDDDConfig();

    boot($config, static fn () => new \stdClass());

    $handles = consumers();
    $this->assertArrayHasKey('test', $handles);
    $this->assertSame($config, $handles['test']->config());
    $this->assertSame('test', $handles['test']->prefix());
    $this->assertSame('test', $handles['test']->label());
  }

  public function test_boot_defers_register_hooks_to_init(): void {
    global $_test_actions;

    boot(new FakeDDDConfig(), static fn () => new \stdClass());

    $this->assertNotEmpty($_test_actions['init'] ?? [], 'boot() must hook init');

    // Firing init runs register_hooks: with the fake config and stub wpdb the
    // outbox/process gates are closed, but the migration trigger always lands.
    do_action('init');
    $this->assertNotEmpty(
      $_test_actions['admin_init'] ?? [],
      'register_hooks (via boot) must register the migration trigger',
    );
  }

  public function test_register_hooks_itself_registers_the_consumer(): void {
    // Consumers wired before boot() existed call register_hooks directly —
    // they must appear in the registry without code changes.
    $config = new FakeDDDConfig();

    \TangibleDDD\WordPress\register_hooks($config, static fn () => new \stdClass());

    $this->assertArrayHasKey('test', consumers());
  }

  public function test_registration_is_idempotent_per_prefix(): void {
    $config = new FakeDDDConfig();
    $getter = static fn () => new \stdClass();

    boot($config, $getter);
    \TangibleDDD\WordPress\register_hooks($config, $getter); // re-announce, same prefix

    $this->assertCount(1, consumers());
  }

  public function test_container_resolves_lazily_through_the_di_getter(): void {
    $container = new \stdClass();
    $calls = 0;
    boot(new FakeDDDConfig(), static function () use (&$calls, $container) {
      ++$calls;
      return $container;
    });

    $this->assertSame(0, $calls, 'di getter must not be called at boot time');
    $this->assertSame($container, consumers()['test']->container());
    $this->assertSame(1, $calls);
  }

  public function test_consumers_pass_through_the_filter(): void {
    boot(new FakeDDDConfig(), static fn () => new \stdClass());

    add_filter('tangible_ddd_consumers', static function (array $handles): array {
      unset($handles['test']);
      return $handles;
    });

    $this->assertSame([], consumers());
  }

  public function test_register_hooks_discovers_tagged_long_processes(): void {
    global $_test_actions;

    // Unique prefix so processes_enabled()'s static cache (keyed by prefix,
    // poisoned to false by the other tests' stub wpdb) probes fresh.
    $config = new class implements IDDDConfig {
      public function prefix(): string { return 'proc'; }
      public function table(string $name): string { return 'wp_proc_' . $name; }
      public function hook(string $name): string { return 'proc_' . $name; }
      public function as_group(string $name): string { return 'proc-' . $name; }
      public function option(string $name): string { return 'proc_' . $name; }
      public function domain_action(string $event_name): string { return 'proc_domain_' . $event_name; }
      public function integration_action(string $event_name): string { return 'proc_integration_' . $event_name; }
      public function version(): string { return 'proc'; }
    };

    // Answer every SHOW TABLES probe with the long_processes table name:
    // the process gate opens, the outbox gate (comparing against its own
    // table name) stays closed.
    $GLOBALS['wpdb'] = new class extends \wpdb {
      public function get_var(?string $query = null, int $x = 0, int $y = 0) {
        return 'wp_proc_long_processes';
      }
    };

    $runner = new ProcessRunner($config, new FakeProcessRepository());
    $container = new class($runner) {
      public function __construct(private readonly ProcessRunner $runner) {}
      public function getServiceIds(): array { return []; }
      public function findTaggedServiceIds(string $tag): array {
        return 'ddd.long_process' === $tag
          ? [FakeGatherProcess::class => [[]]]
          : [];
      }
      public function get(string $id): ProcessRunner { return $this->runner; }
    };

    \TangibleDDD\WordPress\register_hooks($config, static fn () => $container);

    $this->assertNotEmpty(
      $_test_actions[FakeResolvedEvent::integration_action()] ?? [],
      'register_hooks must wire resume hooks for the #[Awaits] events of ddd.long_process-tagged classes',
    );
  }
}
