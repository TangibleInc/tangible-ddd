<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Abi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Infrastructure\AuditSinkFailed;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IConsumerIdentity;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\Persistence\OutboxRepository;
use TangibleDDD\Infra\Persistence\ProcessRepository;
use TangibleDDD\Infra\Services\OutboxIntegrationEventBus;
use TangibleDDD\Runtime\Audit\IAuditSink;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IFactObserver;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\WordPress\Adapter\HostDefaultsWiring;

/**
 * ABI freeze, report D finding F4 (register section 8, wave 2 wp): each
 * live consumer's own IDDDConfig implementation loads UNCHANGED against N,
 * satisfies IDDDConfig and the portable IConsumerIdentity it now extends,
 * keeps deriving the same names, and is accepted by the framework services
 * consumers hand it to.
 *
 * The implementations are verbatim fixture copies (fixtures/consumers,
 * sha256-pinned in manifest.json), never the consumer repositories. Each
 * case runs in its own PHP process so the copies' FQCNs cannot meet a real
 * consumer class (datastream's is autoloadable from .reference/) or each
 * other's registry entries.
 */
#[RunTestsInSeparateProcesses]
final class ConsumerConfigShapeTest extends TestCase {

  /** The eight methods every implementation has (report D 4.1); R3: no method is ever added. */
  private const IDDDCONFIG_METHODS = ['as_group', 'domain_action', 'hook', 'integration_action', 'option', 'prefix', 'table', 'version'];

  protected function setUp(): void {
    $GLOBALS['wpdb'] = new \wpdb(); // prefix 'wp_'; four of the five call `global $wpdb` in table()
    ConsumerRegistry::reset();
  }

  protected function tearDown(): void {
    ConsumerRegistry::reset();
  }

  /** @return array<string, mixed> */
  private static function manifest(): array {
    return json_decode((string) file_get_contents(__DIR__ . '/fixtures/consumers/manifest.json'), true);
  }

  /** @return iterable<string, array{string, array<string, mixed>}> */
  public static function consumers(): iterable {
    foreach (self::manifest()['consumers'] as $name => $entry) {
      yield $name => [$name, $entry];
    }
  }

  public function test_the_fixture_set_is_the_five_live_implementations(): void {
    self::assertSame(['cred', 'lms', 'quiz', 'certificates', 'datastream'], array_keys(self::manifest()['consumers']));
  }

  public function test_IDDDConfig_keeps_exactly_the_eight_consumer_implemented_methods(): void {
    $methods = array_map(static fn (\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(IDDDConfig::class))->getMethods());
    sort($methods);
    self::assertSame(self::IDDDCONFIG_METHODS, array_values(array_unique($methods)), 'a new abstract method on IDDDConfig fatals every consumer (R3, F4)');
    self::assertTrue(is_subclass_of(IDDDConfig::class, IConsumerIdentity::class), 'IDDDConfig extends the portable identity');
    foreach ((new \ReflectionClass(IConsumerIdentity::class))->getMethods() as $m) {
      self::assertContains($m->getName(), self::IDDDCONFIG_METHODS, 'IConsumerIdentity only names methods every consumer already has');
    }
  }

  /** @param array<string, mixed> $entry */
  #[DataProvider('consumers')]
  public function test_the_consumer_config_loads_unchanged_and_derives_the_same_names(string $name, array $entry): void {
    $config = self::load($entry);

    self::assertInstanceOf(IDDDConfig::class, $config);
    self::assertInstanceOf(IConsumerIdentity::class, $config);
    $g = $entry['golden'];
    self::assertSame($g['prefix'], $config->prefix());
    self::assertSame($g['table'], $config->table('integration_outbox'));
    self::assertSame($g['hook'], $config->hook('process_continue'));
    self::assertSame($g['as_group'], $config->as_group('outbox'));
    self::assertSame($g['option'], $config->option('outbox_pauses'));
    self::assertSame($g['domain_action'], $config->domain_action('user_earned'));
    self::assertSame($g['integration_action'], $config->integration_action('user_earned'));
    self::assertSame($g['version'], $config->version());
  }

  /** @param array<string, mixed> $entry */
  #[DataProvider('consumers')]
  public function test_the_framework_accepts_the_consumer_config(string $name, array $entry): void {
    $config = self::load($entry);
    HostDefaultsWiring::register();

    // Registration (ConsumerRegistry::add widened to IConsumerIdentity, CR-SM-3).
    ConsumerRegistry::reset();
    ConsumerRegistry::add($config, static fn () => new ContainerBuilder());
    self::assertSame($config, ConsumerRegistry::config_for($config->prefix()));

    // The 0.6 constructors compiled containers call with it (F5), resolving
    // the wave-2 ports per consumer through HostDefaults::for().
    $outboxConfig = OutboxConfig::from_options($config);
    self::assertSame($entry['golden']['as_group'], $outboxConfig->action_scheduler_group);
    self::assertInstanceOf(CorrelationMiddleware::class, new CorrelationMiddleware($config, new EventsUnitOfWork(), new Redactor()));
    self::assertInstanceOf(OutboxIntegrationEventBus::class, new OutboxIntegrationEventBus(new OutboxRepository($config, $outboxConfig), $config));
    self::assertInstanceOf(ProcessRunner::class, new ProcessRunner($config, new ProcessRepository($config)));
    foreach ([IAuditSink::class, IFactObserver::class, IWakeupScheduler::class, IOutboxAdministration::class] as $port) {
      self::assertInstanceOf($port, HostDefaults::for($port, $config), "HostDefaults::for($port) for $name");
    }

    // Infrastructure signals reach the consumer's legacy hook names (B15).
    global $_test_did_actions;
    $before = $_test_did_actions;
    (new AuditSinkFailed(str_repeat('a', 32), null, 'close', 'probe'))->dispatch($config);
    self::assertSame(($before[$config->prefix() . '_audit_sink_failed'] ?? 0) + 1, $_test_did_actions[$config->prefix() . '_audit_sink_failed'] ?? 0);
    self::assertSame(($before['tangible_ddd_audit_sink_failed'] ?? 0) + 1, $_test_did_actions['tangible_ddd_audit_sink_failed'] ?? 0);
  }

  /** @param array<string, mixed> $entry */
  private static function load(array $entry): IDDDConfig {
    $file = __DIR__ . '/fixtures/consumers/' . $entry['file'];
    self::assertSame($entry['sha256'], hash_file('sha256', $file), "{$entry['file']} is no longer the verbatim copy");
    self::assertFalse(class_exists($entry['class'], false), "{$entry['class']} was already declared elsewhere in this process");

    require $file;
    $ref = new \ReflectionClass($entry['class']);
    self::assertSame(realpath($file), realpath((string) $ref->getFileName()));

    return $ref->newInstanceArgs($entry['args']);
  }
}
