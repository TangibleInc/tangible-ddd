<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Tangible\LMS\Extension\SuperTrace\Application\IntegrationListeners\RecordTraceWhenTraceRecorded;
use Tangible\LMS\Extension\SuperTrace\Application\Process\SuperTraceProcess;
use Tangible\LMS\Extension\SuperTrace\Domain\Events\TraceRecorded;
use TangibleDDD\Application\Process\LongProcessCatalog;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Tests\Fakes\FakeThreeStepProcess;
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

final class ConsumerModuleBootTest extends TestCase
{
    private const HOST_ROOT = 'Tangible\\LMS';
    private const MODULE_ROOT = 'Tangible\\LMS\\Extension\\SuperTrace';

    private static int $prefixSequence = 0;

    private string $hostPrefix;
    private DDDConfig $hostConfig;
    private ProcessRunner $hostRunner;
    private ModuleLifecycleContainer $hostContainer;

    protected function setUp(): void
    {
        global $_test_actions, $_test_action_registrations, $_test_filters;

        $_test_actions = [];
        $_test_action_registrations = [];
        $_test_filters = [];
        $GLOBALS['wpdb'] = new \wpdb();
        ConsumerRegistry::reset();
        if (function_exists('TangibleDDD\\WordPress\\reset_module_runtime')) {
            \TangibleDDD\WordPress\reset_module_runtime();
        }

        $this->hostPrefix = 'module_boot_' . ++self::$prefixSequence;
        $this->hostConfig = new DDDConfig($this->hostPrefix, self::HOST_ROOT, 'test');
        $this->hostRunner = new ProcessRunner($this->hostConfig, new FakeProcessRepository());
        $this->hostContainer = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog(),
            ProcessRunner::class => $this->hostRunner,
        ]);
    }

    protected function tearDown(): void
    {
        ConsumerRegistry::reset();
        if (function_exists('TangibleDDD\\WordPress\\reset_module_runtime')) {
            \TangibleDDD\WordPress\reset_module_runtime();
        }
    }

    public function test_supported_loader_order_registers_the_host_before_the_module(): void
    {
        global $_test_action_registrations;

        add_action('plugins_loaded', function (): void {
            boot(
                $this->hostConfig,
                fn (): ModuleLifecycleContainer => $this->hostContainer,
                'LMS',
                self::HOST_ROOT,
            );
        }, 10);
        add_action('plugins_loaded', function (): void {
            boot_module(
                $this->hostPrefix,
                self::MODULE_ROOT,
                static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
            );
        }, 30);

        self::assertSame(
            [10, 30],
            array_column($_test_action_registrations['plugins_loaded'], 'priority'),
        );

        $this->run_hook_in_priority_order('plugins_loaded');

        self::assertArrayHasKey(self::MODULE_ROOT, ConsumerRegistry::modules_for($this->hostPrefix));
        self::assertSame(
            [2, 3],
            array_column($_test_action_registrations['init'], 'priority'),
            'Host runtime hooks must run before module runtime wiring.',
        );
    }

    public function test_boot_module_fails_immediately_when_the_host_is_missing(): void
    {
        global $_test_action_registrations;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No registered DDD consumer has prefix "missing"');

        try {
            boot_module(
                'missing',
                'Tangible\\Missing\\Extension\\Sidecar',
                static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
            );
        } finally {
            self::assertEmpty($_test_action_registrations['init'] ?? []);
        }
    }

    public function test_boot_module_declares_its_0_6_2_loader_requirement(): void
    {
        $versions = \Tangible_DDD_Versions::instance();
        $requirements = new \ReflectionProperty(\Tangible_DDD_Versions::class, 'requirements');
        $before = $requirements->getValue($versions);

        try {
            $this->registerHost();
            boot_module(
                $this->hostPrefix,
                self::MODULE_ROOT,
                static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
            );

            self::assertSame(
                '0.6.2',
                $requirements->getValue($versions)['ddd-module:' . self::MODULE_ROOT] ?? null,
            );
        } finally {
            $requirements->setValue($versions, $before);
        }
    }

    public function test_same_root_cannot_be_attached_to_a_conflicting_host(): void
    {
        $this->registerHost();
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
        );
        ConsumerRegistry::add(
            new DDDConfig('other_lms', self::HOST_ROOT, 'test'),
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
            'Other LMS',
            self::HOST_ROOT,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered for consumer');

        boot_module(
            'other_lms',
            self::MODULE_ROOT,
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
        );
    }

    public function test_same_root_replaces_its_getter_before_init_and_installs_one_hook(): void
    {
        global $_test_action_registrations;

        $this->registerHost();
        $first = new ModuleLifecycleContainer();
        $second = new ModuleLifecycleContainer([
            RecordTraceWhenTraceRecorded::class => static fn (): RecordTraceWhenTraceRecorded => new RecordTraceWhenTraceRecorded(),
        ]);

        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $first);
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $second);

        self::assertSame(
            $second,
            ConsumerRegistry::owner_of(SuperTraceProcess::class)->container(),
            'The last pre-init getter must own module routing.',
        );
        self::assertCount(1, $this->callbacksAt('init', 3));

        $this->run_callbacks_at('init', 3);

        self::assertNotContains(RecordTraceWhenTraceRecorded::class, $first->getCalls);
        self::assertContains(RecordTraceWhenTraceRecorded::class, $second->getCalls);
        self::assertCount(1, $this->callbacksAt('init', 3));
    }

    public function test_late_getter_replacement_is_rejected_after_runtime_wiring(): void
    {
        $this->registerHost();
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
        );
        $this->run_callbacks_at('init', 3);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already wired');

        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
        );
    }

    #[DataProvider('cataloglessModules')]
    public function test_absent_or_empty_catalog_is_valid_for_listener_only_modules(
        ModuleLifecycleContainer $moduleContainer,
    ): void {
        $this->registerHost();
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            static fn (): ModuleLifecycleContainer => $moduleContainer,
        );

        $this->run_callbacks_at('init', 3);

        self::assertContains(RecordTraceWhenTraceRecorded::class, $moduleContainer->getCalls);
        self::assertNotContains(ProcessRunner::class, $moduleContainer->getCalls);
        self::assertSame([LongProcessCatalog::class], $this->hostContainer->getCalls);
    }

    /** @return iterable<string, array{ModuleLifecycleContainer}> */
    public static function cataloglessModules(): iterable
    {
        $listener = static fn (): RecordTraceWhenTraceRecorded => new RecordTraceWhenTraceRecorded();

        yield 'catalog absent' => [new ModuleLifecycleContainer([
            RecordTraceWhenTraceRecorded::class => $listener,
        ])];
        yield 'catalog empty' => [new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog(),
            RecordTraceWhenTraceRecorded::class => $listener,
        ])];
    }

    public function test_module_process_entries_use_the_hosts_exact_runner(): void
    {
        $this->registerHost();
        $metadata = [['awaits' => [TraceRecorded::class]]];
        $module = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => $metadata,
            ]),
        ]);
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $module);

        $this->run_callbacks_at('init', 3);

        $events = (new \ReflectionProperty(ProcessRunner::class, 'registered_events'))->getValue($this->hostRunner);
        self::assertArrayHasKey(TraceRecorded::class, $events);
        self::assertSame(
            [LongProcessCatalog::class, ProcessRunner::class],
            $this->hostContainer->getCalls,
            'Catalog validation may read the host catalog, then must resolve its exact runner.',
        );
        self::assertNotContains(ProcessRunner::class, $module->getCalls);
    }

    public function test_identical_host_and_module_metadata_is_deduplicated(): void
    {
        global $_test_actions;

        $metadata = [['awaits' => [TraceRecorded::class]]];
        $this->hostContainer = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => $metadata,
            ]),
            ProcessRunner::class => $this->hostRunner,
        ]);
        $this->registerHost();
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer([
                LongProcessCatalog::class => new LongProcessCatalog([
                    SuperTraceProcess::class => $metadata,
                ]),
            ]),
        );

        $moduleCallbacks = $this->callbacksAt('init', 3);
        $this->run_callbacks_at('init', 2);
        foreach ($moduleCallbacks as $callback) {
            $callback();
        }

        self::assertCount(1, $_test_actions[TraceRecorded::integration_action()] ?? []);
        self::assertSame(
            [LongProcessCatalog::class, ProcessRunner::class, LongProcessCatalog::class],
            $this->hostContainer->getCalls,
            'An identical module duplicate must not resolve or re-register the runner.',
        );
    }

    public function test_conflicting_host_metadata_fails_before_module_listeners_boot(): void
    {
        $this->hostContainer = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => [['awaits' => [TraceRecorded::class]]],
            ]),
            ProcessRunner::class => $this->hostRunner,
        ]);
        $this->registerHost();
        $module = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => [[]],
            ]),
            RecordTraceWhenTraceRecorded::class => static fn (): RecordTraceWhenTraceRecorded => new RecordTraceWhenTraceRecorded(),
        ]);
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $module);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(SuperTraceProcess::class);

        try {
            $this->run_callbacks_at('init', 3);
        } finally {
            self::assertNotContains(RecordTraceWhenTraceRecorded::class, $module->getCalls);
            self::assertNotContains(ProcessRunner::class, $this->hostContainer->getCalls);
        }
    }

    public function test_conflicting_prior_module_metadata_fails_before_the_later_module_boots(): void
    {
        $this->registerHost();
        $firstRoot = 'Tangible\\LMS\\Extension';
        $first = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => [['awaits' => [TraceRecorded::class]]],
            ]),
        ]);
        $second = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => [[]],
            ]),
            RecordTraceWhenTraceRecorded::class => static fn (): RecordTraceWhenTraceRecorded => new RecordTraceWhenTraceRecorded(),
        ]);
        boot_module($this->hostPrefix, $firstRoot, static fn (): ModuleLifecycleContainer => $first);
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $second);

        $callbacks = $this->callbacksAt('init', 3);
        $callbacks[0]();

        try {
            $callbacks[1]();
            self::fail('The later conflicting module must fail.');
        } catch (\LogicException $error) {
            self::assertStringContainsString(SuperTraceProcess::class, $error->getMessage());
            self::assertNotContains(RecordTraceWhenTraceRecorded::class, $second->getCalls);
        }
    }

    public function test_identical_prior_module_metadata_is_deduplicated(): void
    {
        global $_test_actions;

        $this->registerHost();
        $firstRoot = 'Tangible\\LMS\\Extension';
        $metadata = [['awaits' => [TraceRecorded::class]]];
        $first = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => $metadata,
            ]),
        ]);
        $second = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => $metadata,
            ]),
        ]);
        boot_module($this->hostPrefix, $firstRoot, static fn (): ModuleLifecycleContainer => $first);
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $second);

        $this->run_callbacks_at('init', 3);

        self::assertCount(1, $_test_actions[TraceRecorded::integration_action()] ?? []);
    }

    public function test_module_catalog_rejects_a_process_outside_its_namespace_before_listeners_boot(): void
    {
        $this->registerHost();
        $module = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                FakeThreeStepProcess::class => [[]],
            ]),
            RecordTraceWhenTraceRecorded::class => static fn (): RecordTraceWhenTraceRecorded => new RecordTraceWhenTraceRecorded(),
        ]);
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $module);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('outside module namespace');

        try {
            $this->run_callbacks_at('init', 3);
        } finally {
            self::assertNotContains(RecordTraceWhenTraceRecorded::class, $module->getCalls);
        }
    }

    public function test_module_catalog_rejects_a_non_process_before_listeners_boot(): void
    {
        $this->registerHost();
        $module = new ModuleLifecycleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                \Tangible\LMS\Extension\SuperTrace\Application\Commands\RecordTrace::class => [[]],
            ]),
            RecordTraceWhenTraceRecorded::class => static fn (): RecordTraceWhenTraceRecorded => new RecordTraceWhenTraceRecorded(),
        ]);
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $module);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not extend LongProcess');

        try {
            $this->run_callbacks_at('init', 3);
        } finally {
            self::assertNotContains(RecordTraceWhenTraceRecorded::class, $module->getCalls);
        }
    }

    public function test_catalog_service_requires_a_runtime_get_api(): void
    {
        $this->registerHost();
        $module = new class {
            public function has(string $id): bool
            {
                return $id === LongProcessCatalog::class;
            }

            public function getServiceIds(): array
            {
                return [];
            }
        };
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): object => $module);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('cannot resolve its process catalog');

        $this->run_callbacks_at('init', 3);
    }

    public function test_host_handle_remains_stable_after_module_registration(): void
    {
        $host = $this->registerHost();
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
        );

        $moduleCallbacks = $this->callbacksAt('init', 3);
        $this->run_callbacks_at('init', 2);
        foreach ($moduleCallbacks as $callback) {
            $callback();
        }

        self::assertSame($host, ConsumerRegistry::consumer($this->hostPrefix));
        self::assertSame($this->hostConfig, ConsumerRegistry::config_for($this->hostPrefix));
        self::assertSame([$this->hostPrefix], array_keys(ConsumerRegistry::all()));
    }

    public function test_conflicting_host_replacement_fails_after_module_registration(): void
    {
        $this->registerHost();
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be replaced after modules are registered');

        ConsumerRegistry::add(
            new DDDConfig($this->hostPrefix, self::HOST_ROOT, 'replacement'),
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
            'Replacement LMS',
            self::HOST_ROOT,
        );
    }

    private function registerHost(): \TangibleDDD\Infra\Consumers\ConsumerHandle
    {
        boot(
            $this->hostConfig,
            fn (): ModuleLifecycleContainer => $this->hostContainer,
            'LMS',
            self::HOST_ROOT,
        );

        return ConsumerRegistry::consumer($this->hostPrefix);
    }

    /** @return list<callable> */
    private function callbacksAt(string $hook, int $priority): array
    {
        global $_test_action_registrations;

        return array_values(array_map(
            static fn (array $registration): callable => $registration['callback'],
            array_filter(
                $_test_action_registrations[$hook] ?? [],
                static fn (array $registration): bool => $registration['priority'] === $priority,
            ),
        ));
    }

    private function run_callbacks_at(string $hook, int $priority): void
    {
        foreach ($this->callbacksAt($hook, $priority) as $callback) {
            $callback();
        }
    }

    private function run_hook_in_priority_order(string $hook): void
    {
        global $_test_action_registrations;

        $registrations = $_test_action_registrations[$hook] ?? [];
        usort(
            $registrations,
            static fn (array $left, array $right): int => $left['priority'] <=> $right['priority'],
        );

        foreach ($registrations as $registration) {
            $registration['callback']();
        }
    }
}

final class ModuleLifecycleContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $getCalls = [];

    /** @param array<string, mixed> $services */
    public function __construct(private array $services = []) {}

    public function get(string $id): mixed
    {
        $this->getCalls[] = $id;

        if (!$this->has($id)) {
            throw new class("No service: $id") extends \RuntimeException implements NotFoundExceptionInterface {};
        }

        $service = $this->services[$id];
        if ($service instanceof Closure) {
            $service = $service();
            $this->services[$id] = $service;
        }

        return $service;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }

    /** @return list<string> */
    public function getServiceIds(): array
    {
        return array_keys($this->services);
    }
}
