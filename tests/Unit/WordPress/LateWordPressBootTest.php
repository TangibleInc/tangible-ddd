<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\IEnvironmentProvider;
use TangibleDDD\Runtime\Delivery\ISubscriberProbe;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\IHostPortFactory;
use TangibleDDD\Runtime\IInfrastructureSignalDispatcher;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Outbox\IOutboxOptionsReader;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\WordPress\Adapter\GetLockProcessLock;
use TangibleDDD\WordPress\Adapter\HasActionSubscriberProbe;
use TangibleDDD\WordPress\Adapter\HostDefaultsWiring;
use TangibleDDD\WordPress\Adapter\WpActorProvider;
use TangibleDDD\WordPress\Adapter\WpdbTransactionBoundary;
use TangibleDDD\WordPress\Adapter\WpEnvironmentProvider;
use TangibleDDD\WordPress\Adapter\WpHookSignalDispatcher;
use TangibleDDD\WordPress\Adapter\WpHookSubscriptionRegistry;
use TangibleDDD\WordPress\Adapter\WpHostPortFactory;
use TangibleDDD\WordPress\Adapter\WpOptionsOutboxConfigReader;

/**
 * Register R2/R3 in the consumer test-bootstrap context (LMS gate, 0.6.99):
 * vendor/autoload.php runs the loader before WordPress or its stubs exist,
 * so hooks.php sees no add_action and nothing reaches plugins_loaded. In
 * 0.6.5 the 0.6 constructors and OutboxConfig::from_options() still worked
 * there, because they called WordPress functions directly at use time.
 *
 * ddd-wp's hooks.php installs a lazy HostDefaults miss resolver in that case
 * (HostDefaultsWiring::register_lazily); the first miss with WordPress
 * functions present fills every WordPress default. The boot itself runs in a
 * child process (fixtures/late-wordpress-boot.php), since this suite's own
 * bootstrap defines WordPress stubs and wires HostDefaults eagerly.
 */
final class LateWordPressBootTest extends TestCase {

  protected function tearDown(): void {
    HostDefaults::reset_for_tests();
    HostDefaultsWiring::register();
  }

  /** @return array<string, mixed> */
  private static function boot(string $mode): array {
    $root = dirname(__DIR__, 3);
    $cmd = sprintf(
      '%s %s %s %s %s 2>&1',
      escapeshellarg(PHP_BINARY),
      escapeshellarg(__DIR__ . '/fixtures/late-wordpress-boot.php'),
      escapeshellarg($root),
      escapeshellarg($root . '/tests/Unit/Abi/fixtures/scaffold/v0.6.2'),
      escapeshellarg($mode),
    );
    exec($cmd, $out, $status);
    $output = implode("\n", $out);
    self::assertSame(0, $status, "late-wordpress-boot.php ($mode) failed:\n$output");
    $report = json_decode($output, true);
    self::assertIsArray($report, "late-wordpress-boot.php ($mode) printed no report:\n$output");
    return $report;
  }

  public function test_an_lms_shaped_container_resolves_when_wordpress_arrives_after_the_loader(): void {
    $r = self::boot('full');

    self::assertFalse($r['wp_at_autoload'], 'the fixture models the late case: no WordPress function at autoload');
    self::assertNull($r['reader_before_wp'], 'before WordPress exists HostDefaults stays empty (R2)');
    self::assertSame(
      \TangibleDDD\Infra\Exceptions\IncorrectUsageException::class,
      $r['from_options_before_wp'],
      'outside WordPress from_options() keeps its documented error'
    );

    self::assertSame([], $r['failures'], 'every public service of the 0.6 scaffold resolves');
    self::assertContains(\TangibleDDD\Application\Persistence\TransactionMiddleware::class, $r['resolved']);
    self::assertContains(\TangibleDDD\Application\Correlation\CorrelationMiddleware::class, $r['resolved']);
    self::assertContains(\TangibleDDD\Infra\Services\OutboxProcessor::class, $r['resolved']);
    self::assertContains(\TangibleDDD\Application\Process\ProcessRunner::class, $r['resolved']);
    self::assertContains(\TangibleDDD\Application\Events\IIntegrationEventBus::class, $r['resolved']);
    self::assertSame('acme_orders', $r['prefix']);

    self::assertSame(7, $r['batch_size'], 'OutboxConfig::from_options reads the option through get_option, as 0.6.5 did');
    self::assertSame('acme_orders-outbox', $r['as_group']);

    self::assertSame('handled', $r['portable_tx'], 'a transactional command runs inside the wpdb boundary, not NoTransactionBoundary');
    self::assertSame(['START TRANSACTION', 'COMMIT'], $r['tx_queries']);

    self::assertSame([
      IClock::class => \TangibleDDD\Runtime\SystemClock::class,
      ITransactionBoundary::class => WpdbTransactionBoundary::class,
      IProcessLock::class => GetLockProcessLock::class,
      ISubscriptionRegistry::class => WpHookSubscriptionRegistry::class,
      IInfrastructureSignalDispatcher::class => WpHookSignalDispatcher::class,
      ISubscriberProbe::class => HasActionSubscriberProbe::class,
      IActorProvider::class => WpActorProvider::class,
      IEnvironmentProvider::class => WpEnvironmentProvider::class,
      IOutboxOptionsReader::class => WpOptionsOutboxConfigReader::class,
      IHostPortFactory::class => WpHostPortFactory::class,
      \Psr\Log\LoggerInterface::class => \TangibleDDD\WordPress\Adapter\ErrorLogLogger::class,
    ], $r['ports'], 'every HostDefaults-backed port of the 0.6 constructors gets its WordPress default');
    self::assertSame(
      \TangibleDDD\WordPress\Adapter\ActionSchedulerWakeupScheduler::class,
      $r['per_consumer'][\TangibleDDD\Runtime\Scheduling\IWakeupScheduler::class],
      'per-consumer ports resolve through the WordPress host port factory'
    );
  }

  public function test_from_options_works_with_only_get_option_defined(): void {
    $r = self::boot('options-only');

    self::assertFalse($r['wp_at_autoload']);
    self::assertSame(7, $r['batch_size']);
  }

  public function test_the_lazy_fill_never_replaces_an_explicitly_provided_port(): void {
    HostDefaults::reset_for_tests();
    $clock = new FrozenClock(new \DateTimeImmutable('2030-01-01'));
    HostDefaults::provide(IClock::class, $clock);

    HostDefaultsWiring::register_lazily();
    $reader = HostDefaults::get(IOutboxOptionsReader::class);

    self::assertInstanceOf(WpOptionsOutboxConfigReader::class, $reader, 'the miss fills the WordPress defaults (stubs define add_action here)');
    self::assertSame($clock, HostDefaults::get(IClock::class), 'the clock provided before the miss is kept');
  }

  public function test_the_resolver_runs_once_and_reset_removes_it(): void {
    HostDefaults::reset_for_tests();
    HostDefaultsWiring::register_lazily();
    HostDefaults::get(IOutboxOptionsReader::class);

    $replacement = new WpOptionsOutboxConfigReader();
    HostDefaults::reset_for_tests();
    self::assertNull(HostDefaults::get(IOutboxOptionsReader::class), 'resetForTests removes the resolver: a reset set is not topped up');

    HostDefaults::provide(IOutboxOptionsReader::class, $replacement);
    self::assertSame($replacement, HostDefaults::get(IOutboxOptionsReader::class));
  }
}
