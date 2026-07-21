<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress;

use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Reference;
use Tangible\LMS\Extension\SuperTrace\Application\IntegrationListeners\RecordTraceWhenTraceRecorded;
use Tangible\LMS\Extension\SuperTrace\Application\Process\SuperTraceProcess;
use Tangible\LMS\Extension\SuperTrace\Domain\Events\TraceRecorded;
use TangibleDDD\Application\Process\LongProcessCatalog;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IProcessRepository;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;

use function TangibleDDD\WordPress\boot;
use function TangibleDDD\WordPress\boot_module;

require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/Commands/RecordTrace.php';
require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Domain/Events/TraceRecorded.php';
require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/IntegrationListeners/RecordTraceWhenTraceRecorded.php';
require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/Process/SuperTraceProcess.php';

$moduleFacade = __DIR__ . '/../../../ddd-wordpress/modules.php';
if (is_file($moduleFacade)) {
    require_once $moduleFacade;
}

final class DumpedConsumerModuleBootTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        global $_test_actions, $_test_action_registrations, $_test_did_actions, $_test_filters;

        $_test_actions = [];
        $_test_action_registrations = [];
        $_test_did_actions = [];
        $_test_filters = [];
        $GLOBALS['wpdb'] = new \wpdb();
        ConsumerRegistry::reset();
        if (function_exists('TangibleDDD\\WordPress\\reset_module_runtime')) {
            \TangibleDDD\WordPress\reset_module_runtime();
        }
    }

    protected function tearDown(): void
    {
        ConsumerRegistry::reset();
        if (function_exists('TangibleDDD\\WordPress\\reset_module_runtime')) {
            \TangibleDDD\WordPress\reset_module_runtime();
        }

        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function test_dumped_host_and_module_catalogs_wire_without_container_mutation(): void
    {
        global $_test_action_registrations;

        $hostBuilder = new ContainerBuilder();
        $hostBuilder->register(IDDDConfig::class, DDDConfig::class)
            ->setArguments(['dumped_lms', 'Tangible\\LMS', 'test'])
            ->setPublic(true);
        $hostBuilder->register(IProcessRepository::class, FakeProcessRepository::class)
            ->setPublic(true);
        $hostBuilder->register(ProcessRunner::class, ProcessRunner::class)
            ->setArguments([
                new Reference(IDDDConfig::class),
                new Reference(IProcessRepository::class),
            ])
            ->setPublic(true);
        DDDCompilerPasses::register($hostBuilder);
        $host = $this->dump($hostBuilder, 'DDDModuleHost');

        $moduleBuilder = new ContainerBuilder();
        $moduleBuilder->register(IDDDConfig::class, DDDConfig::class)
            ->setFactory([ConsumerRegistry::class, 'config_for'])
            ->setArguments(['dumped_lms'])
            ->setPublic(true);
        $moduleBuilder->register(DumpedModuleBootMiddleware::class, DumpedModuleBootMiddleware::class)
            ->setPublic(true);
        $moduleBuilder->register(CommandBus::class, CommandBus::class)
            ->setArguments([new Reference(DumpedModuleBootMiddleware::class)])
            ->setPublic(true);
        $moduleBuilder->register(SuperTraceProcess::class, SuperTraceProcess::class)
            ->setArguments(['not-instantiated'])
            ->addTag('ddd.long_process', [
                'awaits' => [TraceRecorded::class],
            ]);
        $moduleBuilder->register(
            RecordTraceWhenTraceRecorded::class,
            RecordTraceWhenTraceRecorded::class,
        )->setPublic(true);
        DDDCompilerPasses::register($moduleBuilder);
        $module = $this->dump($moduleBuilder, 'DDDModuleSidecar');

        $hostConfig = $host->get(IDDDConfig::class);
        $hostRunner = $host->get(ProcessRunner::class);
        boot(
            $hostConfig,
            static fn (): object => $host,
            'LMS',
            'Tangible\\LMS',
        );
        $hostHandle = ConsumerRegistry::consumer('dumped_lms');
        boot_module(
            'dumped_lms',
            'Tangible\\LMS\\Extension\\SuperTrace',
            static fn (): object => $module,
        );

        $moduleRegistrations = array_values(array_filter(
            $_test_action_registrations['init'],
            static fn (array $registration): bool => $registration['priority'] === 3,
        ));
        $this->runPriority($_test_action_registrations['init'], 2);
        $this->runPriority($moduleRegistrations, 3);

        self::assertFalse(method_exists($host, 'findTaggedServiceIds'));
        self::assertFalse(method_exists($host, 'setDefinition'));
        self::assertFalse(method_exists($module, 'findTaggedServiceIds'));
        self::assertTrue($host->has(LongProcessCatalog::class));
        self::assertTrue($module->has(LongProcessCatalog::class));
        self::assertSame($hostConfig, $module->get(IDDDConfig::class));
        self::assertInstanceOf(CommandBus::class, $module->get(CommandBus::class));
        self::assertSame($hostHandle, ConsumerRegistry::consumer('dumped_lms'));
        self::assertSame($hostRunner, $host->get(ProcessRunner::class));

        $events = (new \ReflectionProperty(ProcessRunner::class, 'registered_events'))->getValue($hostRunner);
        self::assertArrayHasKey(TraceRecorded::class, $events);
    }

    /** @param list<array{callback: callable, priority: int, accepted_args: int}> $registrations */
    private function runPriority(array $registrations, int $priority): void
    {
        foreach ($registrations as $registration) {
            if ($registration['priority'] === $priority) {
                $registration['callback']();
            }
        }
    }

    private function dump(ContainerBuilder $builder, string $prefix): object
    {
        $builder->compile();
        $class = $prefix . str_replace('.', '', uniqid('', true));
        $file = tempnam(sys_get_temp_dir(), 'ddd-module-');
        if ($file === false) {
            throw new \RuntimeException('Could not create a temporary container file');
        }

        $this->temporaryFiles[] = $file;
        file_put_contents($file, (new PhpDumper($builder))->dump(['class' => $class]));
        require $file;

        return new $class();
    }
}

final class DumpedModuleBootMiddleware implements Middleware
{
    public function execute(mixed $command, callable $next): mixed
    {
        return null;
    }
}
