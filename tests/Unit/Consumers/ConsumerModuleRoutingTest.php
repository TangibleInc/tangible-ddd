<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Consumers;

use ArrayObject;
use Closure;
use League\Tactician\CommandBus;
use League\Tactician\Middleware;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Tangible\LMS\Extension\SuperTrace\Application\Commands\RecordTrace;
use Tangible\LMS\Extension\SuperTrace\Application\IntegrationListeners\RecordTraceWhenTraceRecorded;
use Tangible\LMS\Extension\SuperTrace\Application\Process\SuperTraceProcess;
use Tangible\LMS\Extension\SuperTrace\Application\Queries\FindTrace;
use Tangible\LMS\Extension\SuperTrace\Domain\Events\TraceRecorded;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Infra\Consumers\ConsumerHandle;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Fakes\FakeProcessRepository;

use function TangibleDDD\WordPress\register_event_handlers;

require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/Commands/RecordTrace.php';
require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/Queries/FindTrace.php';
require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Domain/Events/TraceRecorded.php';
require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/IntegrationListeners/RecordTraceWhenTraceRecorded.php';
require_once __DIR__ . '/../../Fakes/Sidecar/Tangible/LMS/Extension/SuperTrace/Application/Process/SuperTraceProcess.php';

final class ConsumerModuleRoutingTest extends TestCase
{
    private const HOST_CORRELATION = 'host.correlation';
    private const HOST_TRANSACTION = 'host.transaction';
    private const HOST_PUBLISH = 'host.publish';

    private DDDConfig $hostConfig;
    private ConsumerHandle $hostHandle;
    private ConsumerHandle $moduleHandle;
    private RecordingContainer $hostContainer;
    private RecordingContainer $moduleContainer;
    private ArrayObject $pipeline;
    private Middleware $hostCorrelation;
    private Middleware $hostTransaction;
    private Middleware $hostPublish;

    protected function setUp(): void
    {
        global $_test_actions, $_test_filters;

        $_test_actions = [];
        $_test_filters = [];
        $GLOBALS['wpdb'] = new \wpdb();
        ConsumerRegistry::reset();
        Correlation::reset();

        $this->pipeline = new ArrayObject();
        $this->hostConfig = new DDDConfig(
            prefix: 'tgbl_lms',
            namespace_root: 'Tangible\\LMS',
            version: 'test',
        );
        $this->hostCorrelation = $this->pass_through('host-correlation');
        $this->hostTransaction = $this->pass_through('host-transaction');
        $this->hostPublish = $this->pass_through('host-publish');
        $this->hostContainer = new RecordingContainer([
            self::HOST_CORRELATION => $this->hostCorrelation,
            self::HOST_TRANSACTION => $this->hostTransaction,
            self::HOST_PUBLISH => $this->hostPublish,
        ]);

        $this->hostHandle = ConsumerRegistry::add(
            $this->hostConfig,
            fn (): RecordingContainer => $this->hostContainer,
            'LMS',
        );
        $this->moduleHandle = ConsumerRegistry::add_module(
            'tgbl_lms',
            'Tangible\\LMS\\Extension\\SuperTrace',
            fn (): RecordingContainer => $this->moduleContainer,
        );

        $moduleCommandBus = new CommandBus(
            ConsumerRegistry::service_for('tgbl_lms', self::HOST_CORRELATION),
            ConsumerRegistry::service_for('tgbl_lms', self::HOST_TRANSACTION),
            ConsumerRegistry::service_for('tgbl_lms', self::HOST_PUBLISH),
            $this->terminal('module-command'),
        );
        $moduleQueryBus = new CommandBus($this->terminal('module-query'));

        $this->moduleContainer = new RecordingContainer([
            IDDDConfig::class => $this->hostConfig,
            CommandBus::class => $moduleCommandBus,
            'tactician.query_bus' => $moduleQueryBus,
            RecordTraceWhenTraceRecorded::class => static fn (): RecordTraceWhenTraceRecorded => new RecordTraceWhenTraceRecorded(),
        ]);
    }

    protected function tearDown(): void
    {
        ConsumerRegistry::reset();
        Correlation::reset();
    }

    public function test_module_routes_config_prefix_commands_and_queries_without_becoming_a_consumer(): void
    {
        self::assertSame($this->hostConfig, $this->moduleHandle->config());
        self::assertSame('tgbl_lms', $this->moduleHandle->prefix());
        self::assertSame($this->moduleContainer, ConsumerRegistry::owner_of(RecordTrace::class)->container());
        self::assertSame($this->hostHandle, ConsumerRegistry::owner_of('Tangible\\LMS\\Application\\HostCommand'));

        self::assertSame('module-command', (new RecordTrace('trace-1'))->send());
        self::assertSame('module-query', (new FindTrace('trace-1'))->send());

        self::assertSame(
            ['host-correlation', 'host-transaction', 'host-publish', 'module-command', 'module-query'],
            $this->pipeline->getArrayCopy(),
        );
        self::assertSame('tgbl_lms_integration_trace_recorded', TraceRecorded::integration_action());
        self::assertSame(['tgbl_lms'], array_keys(ConsumerRegistry::all()));
        self::assertSame(
            ['Tangible\\LMS\\Extension\\SuperTrace'],
            array_keys(ConsumerRegistry::modules_for('tgbl_lms')),
        );
        self::assertSame($this->hostConfig, ConsumerRegistry::config_for('tgbl_lms'));
        self::assertSame($this->hostConfig, $this->moduleContainer->get(IDDDConfig::class));
        self::assertFalse(method_exists($this->hostContainer, 'register'));
        self::assertFalse(method_exists($this->hostContainer, 'addCompilerPass'));
    }

    public function test_service_bridge_returns_the_hosts_exact_runtime_instances(): void
    {
        self::assertSame(
            $this->hostCorrelation,
            ConsumerRegistry::service_for('tgbl_lms', self::HOST_CORRELATION),
        );
        self::assertSame(
            $this->hostTransaction,
            ConsumerRegistry::service_for('tgbl_lms', self::HOST_TRANSACTION),
        );
        self::assertSame(
            $this->hostPublish,
            ConsumerRegistry::service_for('tgbl_lms', self::HOST_PUBLISH),
        );
        self::assertSame(
            [
                self::HOST_CORRELATION,
                self::HOST_TRANSACTION,
                self::HOST_PUBLISH,
                self::HOST_CORRELATION,
                self::HOST_TRANSACTION,
                self::HOST_PUBLISH,
            ],
            $this->hostContainer->getCalls,
        );
    }

    public function test_module_listener_boots_from_its_container_and_dispatches_on_the_host_prefixed_action(): void
    {
        register_event_handlers(fn (): RecordingContainer => $this->moduleContainer);

        do_action(TraceRecorded::integration_action(), IntegrationEnvelope::wrap(
            ['trace_id' => 'trace-listener'],
            'correlation-1',
            1,
            'event-1',
        ));

        self::assertContains(RecordTraceWhenTraceRecorded::class, $this->moduleContainer->getCalls);
        self::assertSame(
            ['host-correlation', 'host-transaction', 'host-publish', 'module-command'],
            $this->pipeline->getArrayCopy(),
        );
        self::assertNull(Correlation::peek());
    }

    public function test_host_runner_executes_a_module_process_whose_command_routes_to_the_module_bus(): void
    {
        $repository = new FakeProcessRepository();
        $runner = new ProcessRunner($this->hostConfig, $repository);
        $process = new SuperTraceProcess('trace-process');

        $runner->start($process);

        self::assertSame('completed', $process->status());
        self::assertSame($process, $repository->find(1));
        self::assertSame(
            ['host-correlation', 'host-transaction', 'host-publish', 'module-command'],
            $this->pipeline->getArrayCopy(),
        );
        self::assertNull(Correlation::peek());
    }

    public function test_module_registration_requires_an_existing_host(): void
    {
        ConsumerRegistry::reset();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No registered DDD consumer has prefix "missing"');

        ConsumerRegistry::add_module(
            'missing',
            'Tangible\\Missing\\Extension\\Sidecar',
            static fn (): object => new \stdClass(),
        );
    }

    public function test_module_registration_rejects_an_external_notifications_namespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a strict descendant');

        ConsumerRegistry::add_module(
            'tgbl_lms',
            'Tangible\\Notifications',
            static fn (): object => new \stdClass(),
        );
    }

    public function test_module_registration_cannot_bypass_a_more_specific_consumer(): void
    {
        ConsumerRegistry::reset();
        ConsumerRegistry::add(
            new DDDConfig('tangible', 'Tangible', 'test'),
            static fn (): object => new \stdClass(),
        );
        ConsumerRegistry::add(
            new DDDConfig('tgbl_lms', 'Tangible\\LMS', 'test'),
            static fn (): object => new \stdClass(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('belongs to the more specific consumer "tgbl_lms"');

        ConsumerRegistry::add_module(
            'tangible',
            'Tangible\\LMS\\Extension\\SuperTrace',
            static fn (): object => new \stdClass(),
        );
    }

    public function test_service_bridge_requires_a_runtime_container_get_api(): void
    {
        ConsumerRegistry::reset();
        ConsumerRegistry::add(
            new DDDConfig('opaque', 'Opaque', 'test'),
            static fn (): object => new \stdClass(),
        );

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('container cannot resolve services');

        ConsumerRegistry::service_for('opaque', 'anything');
    }

    private function pass_through(string $name): Middleware
    {
        return new class($this->pipeline, $name) implements Middleware {
            public function __construct(
                private readonly ArrayObject $pipeline,
                private readonly string $name,
            ) {}

            public function execute($command, callable $next): mixed
            {
                $this->pipeline[] = $this->name;

                return $next($command);
            }
        };
    }

    private function terminal(string $name): Middleware
    {
        return new class($this->pipeline, $name) implements Middleware {
            public function __construct(
                private readonly ArrayObject $pipeline,
                private readonly string $name,
            ) {}

            public function execute($command, callable $next): string
            {
                $this->pipeline[] = $this->name;

                return $this->name;
            }
        };
    }
}

final class RecordingContainer implements ContainerInterface
{
    /** @var list<string> */
    public array $getCalls = [];

    /** @param array<string, mixed> $services */
    public function __construct(private array $services) {}

    public function get(string $id): mixed
    {
        $this->getCalls[] = $id;

        if (!array_key_exists($id, $this->services)) {
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
