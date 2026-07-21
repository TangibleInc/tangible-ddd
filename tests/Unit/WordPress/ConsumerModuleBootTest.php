<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\WordPress;

use Closure;
use League\Tactician\CommandBus;
use League\Tactician\Middleware;
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
use TangibleDDD\Infra\IDDDConfig;
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
    private CommandBus $moduleBus;

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

        $this->hostPrefix = 'module_boot_' . ++self::$prefixSequence;
        $this->hostConfig = new DDDConfig($this->hostPrefix, self::HOST_ROOT, 'test');
        $this->hostRunner = new ProcessRunner($this->hostConfig, new FakeProcessRepository());
        $this->moduleBus = new CommandBus(new ModuleNoopMiddleware());
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

    public function test_boot_module_rejects_registration_after_init_before_installing_a_route(): void
    {
        $caught = null;
        $callbackCounts = [];
        add_action('init', function () use (&$caught, &$callbackCounts): void {
            $callbackCounts[] = count($this->callbacksAt('init', 3));
            try {
                boot_module(
                    $this->hostPrefix,
                    self::MODULE_ROOT,
                    fn (): ModuleLifecycleContainer => $this->moduleContainer(),
                );
            } catch (\LogicException $error) {
                $caught = $error;
            } finally {
                $callbackCounts[] = count($this->callbacksAt('init', 3));
            }
        }, 1);
        $this->registerHost();

        do_action('init');

        self::assertInstanceOf(\LogicException::class, $caught);
        self::assertStringContainsString('before WordPress init begins', $caught->getMessage());
        self::assertSame([], ConsumerRegistry::modules_for($this->hostPrefix));
        self::assertCount(2, $callbackCounts);
        self::assertSame($callbackCounts[0], $callbackCounts[1]);
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
        ConsumerRegistry::add(
            new DDDConfig('other_lms', self::HOST_ROOT, 'test'),
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
            'Other LMS',
            self::HOST_ROOT,
        );
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
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
        $second = $this->moduleContainer([
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
            fn (): ModuleLifecycleContainer => $this->moduleContainer(),
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

    public function test_module_listener_construction_errors_propagate_and_leave_the_module_unwired(): void
    {
        $this->registerHost();
        $failure = new \RuntimeException('module listener construction failed');
        $module = $this->moduleContainer([
            RecordTraceWhenTraceRecorded::class => static function () use ($failure): never {
                throw $failure;
            },
        ]);
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $module);

        $caught = null;
        try {
            $this->run_callbacks_at('init', 3);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }

        self::assertSame($failure, $caught);
        self::assertFalse(
            \TangibleDDD\WordPress\module_runtime_state()['boots'][self::MODULE_ROOT]['wired'],
        );
    }

    #[DataProvider('invalidModuleContracts')]
    public function test_invalid_module_contract_fails_before_listener_side_effects(
        string $violation,
        string $expectedMessage,
    ): void {
        $this->registerHost();
        $services = [
            IDDDConfig::class => $this->hostConfig,
            CommandBus::class => $this->moduleBus,
            RecordTraceWhenTraceRecorded::class => static fn (): RecordTraceWhenTraceRecorded => new RecordTraceWhenTraceRecorded(),
        ];

        if ($violation === 'missing-config') {
            unset($services[IDDDConfig::class]);
        } elseif ($violation === 'foreign-config') {
            $services[IDDDConfig::class] = new DDDConfig('foreign', self::HOST_ROOT, 'test');
        } elseif ($violation === 'missing-command-bus') {
            unset($services[CommandBus::class]);
        } else {
            $services[CommandBus::class] = new \stdClass();
        }

        $module = new ModuleLifecycleContainer($services);
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): ModuleLifecycleContainer => $module);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($expectedMessage);

        try {
            $this->run_callbacks_at('init', 3);
        } finally {
            self::assertNotContains(RecordTraceWhenTraceRecorded::class, $module->getCalls);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidModuleContracts(): iterable
    {
        yield 'missing host config' => ['missing-config', IDDDConfig::class];
        yield 'different host config object' => ['foreign-config', 'exact config object'];
        yield 'missing command bus' => ['missing-command-bus', CommandBus::class];
        yield 'invalid command bus' => ['invalid-command-bus', 'not a CommandBus'];
    }

    #[DataProvider('cataloglessModules')]
    /** @param array<string, mixed> $moduleServices */
    public function test_absent_or_empty_catalog_is_valid_for_listener_only_modules(
        array $moduleServices,
    ): void {
        $moduleContainer = $this->moduleContainer($moduleServices);
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

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function cataloglessModules(): iterable
    {
        $listener = static fn (): RecordTraceWhenTraceRecorded => new RecordTraceWhenTraceRecorded();

        yield 'catalog absent' => [[
            RecordTraceWhenTraceRecorded::class => $listener,
        ]];
        yield 'catalog empty' => [[
            LongProcessCatalog::class => new LongProcessCatalog(),
            RecordTraceWhenTraceRecorded::class => $listener,
        ]];
    }

    public function test_module_process_entries_use_the_hosts_exact_runner(): void
    {
        $this->registerHost();
        $metadata = [['awaits' => [TraceRecorded::class]]];
        $module = $this->moduleContainer([
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
            fn (): ModuleLifecycleContainer => $this->moduleContainer([
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
        $module = $this->moduleContainer([
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
        $first = $this->moduleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => [['awaits' => [TraceRecorded::class]]],
            ]),
        ]);
        $second = $this->moduleContainer([
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
        $first = $this->moduleContainer([
            LongProcessCatalog::class => new LongProcessCatalog([
                SuperTraceProcess::class => $metadata,
            ]),
        ]);
        $second = $this->moduleContainer([
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
        $module = $this->moduleContainer([
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
        $module = $this->moduleContainer([
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

    public function test_module_container_requires_a_runtime_get_api(): void
    {
        $this->registerHost();
        $module = new class {
            public function has(string $id): bool
            {
                return $id === LongProcessCatalog::class;
            }

            /** @return list<string> */
            public function getServiceIds(): array
            {
                return [];
            }
        };
        boot_module($this->hostPrefix, self::MODULE_ROOT, static fn (): object => $module);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('must expose has() and get()');

        $this->run_callbacks_at('init', 3);
    }

    public function test_host_handle_remains_stable_after_module_registration(): void
    {
        $host = $this->registerHost();
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            fn (): ModuleLifecycleContainer => $this->moduleContainer(),
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

    #[DataProvider('topLevelRootsThatWouldInvalidateModuleOwnership')]
    public function test_later_top_level_consumer_cannot_invalidate_or_partition_module_ownership(
        string $namespaceRoot,
    ): void {
        $this->registerHost();
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            fn (): ModuleLifecycleContainer => $this->moduleContainer(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('would invalidate or partition module');

        try {
            ConsumerRegistry::add(
                new DDDConfig('competing_' . self::$prefixSequence, $namespaceRoot, 'test'),
                static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
                'Competing Consumer',
                $namespaceRoot,
            );
        } finally {
            self::assertSame([$this->hostPrefix], array_keys(ConsumerRegistry::all()));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function topLevelRootsThatWouldInvalidateModuleOwnership(): iterable
    {
        yield 'equal host root ambiguity' => [self::HOST_ROOT];
        yield 'more-specific owner at module root' => [self::HOST_ROOT . '\\Extension'];
        yield 'equal module root ambiguity' => [self::MODULE_ROOT];
        yield 'partition below module root' => [self::MODULE_ROOT . '\\Feature'];
    }

    public function test_later_broader_top_level_consumer_does_not_invalidate_module_ownership(): void
    {
        $this->registerHost();
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            fn (): ModuleLifecycleContainer => $this->moduleContainer(),
        );

        ConsumerRegistry::add(
            new DDDConfig('broader_' . self::$prefixSequence, 'Tangible', 'test'),
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
            'Broader Consumer',
            'Tangible',
        );

        self::assertSame(
            self::MODULE_ROOT,
            ConsumerRegistry::owner_of(SuperTraceProcess::class)->namespace_root(),
        );
    }

    public function test_replacing_an_unrelated_consumer_cannot_partition_module_ownership(): void
    {
        $this->registerHost();
        $competingPrefix = 'replacement_' . self::$prefixSequence;
        $original = ConsumerRegistry::add(
            new DDDConfig($competingPrefix, 'Tangible\\Other', 'test'),
            static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
            'Unrelated Consumer',
            'Tangible\\Other',
        );
        boot_module(
            $this->hostPrefix,
            self::MODULE_ROOT,
            fn (): ModuleLifecycleContainer => $this->moduleContainer(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('would invalidate or partition module');

        try {
            ConsumerRegistry::add(
                new DDDConfig($competingPrefix, self::MODULE_ROOT . '\\Feature', 'test'),
                static fn (): ModuleLifecycleContainer => new ModuleLifecycleContainer(),
                'Replacement Consumer',
                self::MODULE_ROOT . '\\Feature',
            );
        } finally {
            self::assertSame($original, ConsumerRegistry::consumer($competingPrefix));
        }
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

    /** @param array<string, mixed> $services */
    private function moduleContainer(array $services = []): ModuleLifecycleContainer
    {
        return new ModuleLifecycleContainer(array_replace([
            IDDDConfig::class => $this->hostConfig,
            CommandBus::class => $this->moduleBus,
        ], $services));
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

final class ModuleNoopMiddleware implements Middleware
{
    public function execute(mixed $command, callable $next): mixed
    {
        return null;
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
