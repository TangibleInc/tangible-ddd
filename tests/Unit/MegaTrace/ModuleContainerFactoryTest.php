<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\LongProcessCatalog;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\MegaTrace\Module\ModuleContainerFactory;
use TangibleDDD\MegaTrace\Module\ModuleDefinition;

final class ModuleContainerFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        ConsumerRegistry::reset();
    }

    public function test_module_bus_reuses_host_brackets_and_executes_sidecar_commands(): void
    {
        $config = new ModuleTestConfig();
        $correlation = new RecordingMiddleware();
        $transaction = new RecordingMiddleware();
        $events = new RecordingMiddleware();
        $bridge = new Fixtures\ModuleBridgeService();
        $host = new ContainerBuilder();
        $host->set(CorrelationMiddleware::class, $correlation);
        $host->set('host.transaction', $transaction);
        $host->set(DomainEventsPublishMiddleware::class, $events);
        $host->set(Fixtures\ModuleBridge::class, $bridge);
        $host->compile();

        ConsumerRegistry::add(
            $config,
            static fn (): ContainerBuilder => $host,
            'Host',
            __NAMESPACE__,
        );

        $module = (new ModuleContainerFactory())->build(new ModuleDefinition(
            host_prefix: 'module_test',
            namespace_root: __NAMESPACE__ . '\\Fixtures',
            transaction_service_id: 'host.transaction',
            services: [Fixtures\ModuleProbe::class],
            processes: [Fixtures\ModuleProcess::class],
            bridged_services: [Fixtures\ModuleBridge::class],
        ));

        $module->get(CommandBus::class)->handle(new Fixtures\ModuleCommand());

        self::assertSame($config, $module->get(IDDDConfig::class));
        self::assertSame(1, $correlation->calls);
        self::assertSame(1, $transaction->calls);
        self::assertSame(1, $events->calls);
        self::assertSame(1, $module->get(Fixtures\ModuleProbe::class)->handled);
        self::assertSame($bridge, $module->get(Fixtures\ModuleProbe::class)->bridge);
        self::assertSame(
            [Fixtures\ModuleProcess::class],
            array_keys($module->get(LongProcessCatalog::class)->all()),
        );
    }
}

final class RecordingMiddleware implements Middleware
{
    public int $calls = 0;

    public function execute($command, callable $next)
    {
        $this->calls++;
        return $next($command);
    }
}

final class ModuleTestConfig implements IDDDConfig
{
    public function prefix(): string { return 'module_test'; }
    public function table(string $name): string { return 'wp_module_test_' . $name; }
    public function hook(string $name): string { return 'module_test_' . $name; }
    public function as_group(string $name): string { return 'module-test-' . $name; }
    public function option(string $name): string { return 'module_test_' . $name; }
    public function domain_action(string $event_name): string { return 'module_test_domain_' . $event_name; }
    public function integration_action(string $event_name): string { return 'module_test_integration_' . $event_name; }
    public function version(): string { return 'test'; }
}

namespace TangibleDDD\Tests\Unit\MegaTrace\Fixtures;

use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;

interface ModuleBridge
{
}

final class ModuleBridgeService implements ModuleBridge
{
}

final class ModuleProbe
{
    public int $handled = 0;

    public function __construct(public readonly ModuleBridge $bridge)
    {
    }
}

final class ModuleCommand extends SelfHandlingCommand
{
    protected function handle(ModuleProbe $probe): void
    {
        $probe->handled++;
    }
}

final class ModuleProcess extends LongProcess
{
    protected function initialize(): Result
    {
        return new Result();
    }
}
