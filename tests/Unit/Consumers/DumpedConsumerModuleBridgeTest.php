<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Consumers;

use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Tangible\LMS\Extension\SuperTrace\Application\Commands\RecordTrace;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\IDDDConfig;

require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/Commands/RecordTrace.php';

final class DumpedConsumerModuleBridgeTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        ConsumerRegistry::reset();
        DumpedBridgeMiddleware::$calls = [];
    }

    protected function tearDown(): void
    {
        ConsumerRegistry::reset();

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_separately_dumped_module_uses_host_instances_without_mutating_the_host_container(): void
    {
        $hostBuilder = new ContainerBuilder();
        $hostBuilder->register(IDDDConfig::class, DDDConfig::class)
            ->setArguments(['tgbl_lms', 'Tangible\\LMS', 'test'])
            ->setPublic(true);
        $hostBuilder->register('host.correlation', DumpedBridgeMiddleware::class)
            ->setArguments(['host-correlation'])
            ->setPublic(true);
        $hostBuilder->register('host.transaction', DumpedBridgeMiddleware::class)
            ->setArguments(['host-transaction'])
            ->setPublic(true);
        $hostBuilder->register('host.publish', DumpedBridgeMiddleware::class)
            ->setArguments(['host-publish'])
            ->setPublic(true);

        $host = $this->dump($hostBuilder, 'DDDSpikeHostContainer');
        $hostConfig = $host->get(IDDDConfig::class);
        ConsumerRegistry::add(
            $hostConfig,
            static fn (): object => $host,
            'LMS',
        );

        $moduleBuilder = new ContainerBuilder();
        $moduleBuilder->register(IDDDConfig::class, DDDConfig::class)
            ->setFactory([ConsumerRegistry::class, 'config_for'])
            ->setArguments(['tgbl_lms'])
            ->setPublic(true);

        foreach (['correlation', 'transaction', 'publish'] as $service) {
            $moduleBuilder->register("module.host.$service", DumpedBridgeMiddleware::class)
                ->setFactory([ConsumerRegistry::class, 'service_for'])
                ->setArguments(['tgbl_lms', "host.$service"])
                ->setPublic(true);
        }

        $moduleBuilder->register('module.terminal', DumpedModuleTerminal::class)
            ->setPublic(true);
        $moduleBuilder->register(CommandBus::class, CommandBus::class)
            ->setArguments([
                new Reference('module.host.correlation'),
                new Reference('module.host.transaction'),
                new Reference('module.host.publish'),
                new Reference('module.terminal'),
            ])
            ->setPublic(true);

        $module = $this->dump($moduleBuilder, 'DDDSpikeModuleContainer');
        ConsumerRegistry::add_module(
            'tgbl_lms',
            'Tangible\\LMS\\Extension\\SuperTrace',
            static fn (): object => $module,
        );

        self::assertFalse(method_exists($host, 'findTaggedServiceIds'));
        self::assertFalse(method_exists($host, 'setDefinition'));
        self::assertFalse(method_exists($module, 'findTaggedServiceIds'));
        self::assertSame($hostConfig, $module->get(IDDDConfig::class));
        self::assertSame($host->get('host.correlation'), $module->get('module.host.correlation'));
        self::assertSame($host->get('host.transaction'), $module->get('module.host.transaction'));
        self::assertSame($host->get('host.publish'), $module->get('module.host.publish'));

        self::assertSame('dumped-module', (new RecordTrace('trace-dumped'))->send());
        self::assertSame(
            ['host-correlation', 'host-transaction', 'host-publish', 'module-terminal'],
            DumpedBridgeMiddleware::$calls,
        );
    }

    private function dump(ContainerBuilder $builder, string $prefix): object
    {
        $builder->compile();
        $class = $prefix . str_replace('.', '', uniqid('', true));
        $file = tempnam(sys_get_temp_dir(), 'ddd-sidecar-');
        if ($file === false) {
            throw new \RuntimeException('Could not create a temporary container file');
        }
        $this->temporaryFiles[] = $file;
        file_put_contents($file, (new PhpDumper($builder))->dump(['class' => $class]));
        require $file;

        return new $class();
    }
}

final class DumpedBridgeMiddleware implements Middleware
{
    /** @var list<string> */
    public static array $calls = [];

    public function __construct(private readonly string $name) {}

    public function execute($command, callable $next): mixed
    {
        self::$calls[] = $this->name;

        return $next($command);
    }
}

final class DumpedModuleTerminal implements Middleware
{
    public function execute($command, callable $next): string
    {
        DumpedBridgeMiddleware::$calls[] = 'module-terminal';

        return 'dumped-module';
    }
}
