<?php
/**
 * Child process of LateWordPressBootTest: boots the way a consumer's
 * integration test bootstrap does (tangible-lms tests/Integration/bootstrap.php)
 * and prints a JSON report on stdout.
 *
 *   1. vendor/autoload.php first, with no WordPress function defined: the
 *      loader's files entry initializes the winner at once and includes
 *      packages/ddd-wp/wordpress/hooks.php, whose add_action guard is false.
 *   2. Then WordPress stubs, as LMS defines them (add_action, add_filter,
 *      apply_filters, get_option backed by an array, update_option,
 *      is_multisite, get_locale) and a minimal wpdb. No plugins_loaded ever
 *      fires and nothing calls HostDefaultsWiring.
 *   3. Then the consumer's container: the v0.6.2..v0.6.6 scaffold
 *      services.yaml (the spine LMS hand-wires: OutboxConfig::from_options
 *      factory, TransactionMiddleware, CorrelationMiddleware, OutboxProcessor,
 *      OutboxIntegrationEventBus, ProcessRunner), compiled and resolved.
 *
 * Mode `options-only` (argv[3]) defines only get_option: 0.6.5's
 * from_options() needed nothing else.
 *
 * argv: <repo root> <scaffold dir> [full|options-only]
 */

declare(strict_types=1);

[, $root, $scaffold] = $argv;
$mode = $argv[3] ?? 'full';
$report = ['mode' => $mode];

require $root . '/vendor/autoload.php';

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\Outbox\IOutboxOptionsReader;

// ── 1. before WordPress: HostDefaults stays empty (register R2) ─────────────
$report['wp_at_autoload'] = function_exists('add_action') || function_exists('get_option');
$report['reader_before_wp'] = HostDefaults::get(IOutboxOptionsReader::class) === null ? null : get_class(HostDefaults::get(IOutboxOptionsReader::class));
try {
  OutboxConfig::from_options(new \TangibleDDD\Infra\DDDConfig('acme_orders', 'AcmeOrders', 'dev'));
  $report['from_options_before_wp'] = 'returned';
} catch (\Throwable $e) {
  $report['from_options_before_wp'] = get_class($e);
}

// ── 2. the consumer bootstrap's WordPress stubs ─────────────────────────────
// Declared conditionally, as LMS does, so PHP does not hoist them to compile
// time (an unconditional top-level function exists before step 1 runs).
$GLOBALS['__test_wp_options'] = [];
if (!function_exists('get_option')) {
  function get_option(string $option, mixed $default = false): mixed {
    return array_key_exists($option, $GLOBALS['__test_wp_options']) ? $GLOBALS['__test_wp_options'][$option] : $default;
  }
}
if ($mode === 'full' && !function_exists('add_action')) {
  function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
    foreach ($GLOBALS['__test_wp_filters'][$hook] ?? [] as $callback) {
      $value = $callback($value, ...$args);
    }
    return $value;
  }
  function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    $GLOBALS['__test_wp_filters'][$hook][] = $callback;
    return true;
  }
  function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
    return add_filter($hook, $callback, $priority, $accepted_args);
  }
  function update_option(string $option, mixed $value, mixed $autoload = null): bool {
    $GLOBALS['__test_wp_options'][$option] = $value;
    return true;
  }
  function is_multisite(): bool {
    return false;
  }
  function get_locale(): string {
    return 'en_US';
  }
  class wpdb {
    public string $prefix = 'wptests_';
    /** @var list<string> */
    public array $queries = [];
    public function query(string $query) { $this->queries[] = $query; return true; }
    public function prepare(string $query, ...$args): string { return $query; }
    public function get_var(?string $query = null, int $x = 0, int $y = 0) { return null; }
  }
  $GLOBALS['wpdb'] = new wpdb();
}

// ── 3. the consumer container ────────────────────────────────────────────────
spl_autoload_register(static function (string $class) use ($scaffold): void {
  if (str_starts_with($class, 'AcmeOrders\\')) {
    $file = $scaffold . '/ddd-src/' . str_replace('\\', '/', substr($class, strlen('AcmeOrders\\'))) . '.php';
    if (is_file($file)) {
      require $file;
    }
  }
});

$config = new \TangibleDDD\Infra\DDDConfig('acme_orders', 'AcmeOrders', 'dev');
$GLOBALS['__test_wp_options'][$config->option('outbox_batch_size')] = '7';

if ($mode === 'options-only') {
  $report['batch_size'] = OutboxConfig::from_options($config)->batch_size;
  echo json_encode($report);
  exit(0);
}

$builder = new ContainerBuilder();
$builder->setParameter('acme_orders.version', 'dev');
$loader = new YamlFileLoader($builder, new FileLocator($scaffold . '/ddd-wordpress/di'));
$loader->load('tactician.yaml');
$loader->load('services.yaml');
DDDCompilerPasses::register($builder);
$builder->compile();

$failures = [];
$resolved = [];
foreach ($builder->getDefinitions() as $id => $definition) {
  if (!$definition->isPublic() || $definition->isAbstract() || $definition->isSynthetic() || $id === 'service_container') {
    continue;
  }
  try {
    $builder->get($id);
    $resolved[] = $id;
  } catch (\Throwable $e) {
    $failures[$id] = get_class($e) . ': ' . $e->getMessage();
  }
}
$report['failures'] = $failures;
$report['resolved'] = $resolved;
$report['prefix'] = $builder->get(IDDDConfig::class)->prefix();
$report['batch_size'] = $builder->get(OutboxConfig::class)->batch_size;
$report['as_group'] = $builder->get(OutboxConfig::class)->action_scheduler_group;

// The portable middleware with no boundary argument: in 0.6.5 every
// transactional command ran inside a wpdb transaction; it must not throw
// NoTransactionBoundary here.
$command = new class implements \TangibleDDD\Application\Commands\ITransactionalCommand {};
try {
  $report['portable_tx'] = (new TransactionalCommandMiddleware())->execute($command, static fn () => 'handled');
} catch (\Throwable $e) {
  $report['portable_tx'] = get_class($e) . ': ' . $e->getMessage();
}
$report['tx_queries'] = $GLOBALS['wpdb']->queries;

// Every HostDefaults-backed port the 0.6 constructors fall back to.
$ports = [
  \TangibleDDD\Runtime\IClock::class,
  \TangibleDDD\Runtime\ITransactionBoundary::class,
  \TangibleDDD\Runtime\Lock\IProcessLock::class,
  \TangibleDDD\Runtime\Delivery\ISubscriptionRegistry::class,
  \TangibleDDD\Runtime\IInfrastructureSignalDispatcher::class,
  \TangibleDDD\Runtime\Delivery\ISubscriberProbe::class,
  \TangibleDDD\Runtime\Audit\IActorProvider::class,
  \TangibleDDD\Runtime\Audit\IEnvironmentProvider::class,
  IOutboxOptionsReader::class,
  \TangibleDDD\Runtime\IHostPortFactory::class,
  \Psr\Log\LoggerInterface::class,
];
$report['ports'] = [];
foreach ($ports as $port) {
  $impl = HostDefaults::get($port);
  $report['ports'][$port] = $impl === null ? null : get_class($impl);
}
foreach ([\TangibleDDD\Runtime\Audit\IAuditSink::class, \TangibleDDD\Runtime\Process\IProcessStore::class, \TangibleDDD\Runtime\Scheduling\IWakeupScheduler::class] as $port) {
  $impl = HostDefaults::for($port, $config);
  $report['per_consumer'][$port] = $impl === null ? null : get_class($impl);
}

echo json_encode($report);
