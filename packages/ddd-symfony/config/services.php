<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use League\Tactician\CommandBus;
use League\Tactician\Handler\CommandHandlerMiddleware;
use League\Tactician\Handler\Mapping\MapByNamingConvention;
use League\Tactician\Handler\Mapping\MethodName\Handle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use TangibleDDD\Application\BehaviourWorkflows\IWorkflowIgnitionLedger;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgniter;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\CQRS\HandlerClassNameInflector;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Application\Events\EventRouter;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Persistence\TransactionalCommandMiddleware;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Domain\ValueObjects\Behaviours\BehaviourTypes;
use TangibleDDD\Domain\ValueObjects\Behaviours\IBehaviourTypes;
use TangibleDDD\Runtime\Audit\AttributeAuditPolicy;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\NullAuditSink;
use TangibleDDD\Runtime\Audit\PhpEnvironmentProvider;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\IRelayWakeup;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\Effects\EffectMiddleware;
use TangibleDDD\Runtime\Effects\IEffectJournal;
use TangibleDDD\Runtime\Effects\ITracksEffectState;
use TangibleDDD\Runtime\Effects\UnrecordedEffects;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Ops\IOperatorView;
use TangibleDDD\Runtime\Ops\PortOperatorView;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;
use TangibleDDD\Runtime\SystemClock;
use TangibleDDD\Symfony\Bundle\ConsumerSettings;
use TangibleDDD\Symfony\Console\Ops\DlqDiscardCommand;
use TangibleDDD\Symfony\Console\Ops\DlqListCommand;
use TangibleDDD\Symfony\Console\Ops\DlqReplayCommand;
use TangibleDDD\Symfony\Console\Ops\DlqRetryCommand;
use TangibleDDD\Symfony\Console\Ops\EffectsInvalidateCommand;
use TangibleDDD\Symfony\Console\Ops\OpsListCommand;
use TangibleDDD\Symfony\Console\Ops\PauseCommand;
use TangibleDDD\Symfony\Console\Ops\ResumeCommand;
use TangibleDDD\Symfony\Console\Ops\StrandedCommand;
use TangibleDDD\Symfony\Console\RelayCommand;
use TangibleDDD\Symfony\Console\SchemaDumpCommand;
use TangibleDDD\Symfony\Messenger\ConsumerRouter;
use TangibleDDD\Symfony\Messenger\FactAudience;
use TangibleDDD\Symfony\Messenger\IntegrationFactHandler;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Messenger\MessengerFactTransport;
use TangibleDDD\Symfony\Messenger\OutboxFactClassResolver;
use TangibleDDD\Symfony\Messenger\ProcessWakeupHandler;
use TangibleDDD\Symfony\Messenger\ProcessWakeupMessage;
use TangibleDDD\Symfony\Ops\ConsumersOperatorView;
use TangibleDDD\Symfony\Ops\CoreStrandedRepairs;
use TangibleDDD\Symfony\Ops\DbalLedgerOperatorSource;
use TangibleDDD\Symfony\Ops\DbalUnheardFactSource;
use TangibleDDD\Symfony\Ops\DbalWakeupOperatorSource;
use TangibleDDD\Symfony\Ops\DbalWorkflowOperatorSource;
use TangibleDDD\Symfony\Ops\MessengerFailureTransportSource;
use TangibleDDD\Symfony\Persistence\DbalBehaviourWorkflowRepository;
use TangibleDDD\Symfony\Persistence\DbalDeliveryLedger;
use TangibleDDD\Symfony\Persistence\DbalEffectJournal;
use TangibleDDD\Symfony\Persistence\DbalOutboxAdministration;
use TangibleDDD\Symfony\Persistence\DbalParkingScheduler;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalProcessStore;
use TangibleDDD\Symfony\Persistence\DbalRelayPauseStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Persistence\DbalWorkflowIgnitionLedger;
use TangibleDDD\Symfony\Persistence\DbalWorkItemRepository;
use TangibleDDD\Symfony\Persistence\EntityManagerSession;
use TangibleDDD\Symfony\Persistence\ParkedFacts;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;
use TangibleDDD\Symfony\Runtime\Actor\ActorContext;
use TangibleDDD\Symfony\Runtime\Actor\ConsoleOperatorActorProvider;
use TangibleDDD\Symfony\Runtime\Actor\SecurityUserActorProvider;
use TangibleDDD\Symfony\Runtime\Actor\SymfonyActorProvider;
use TangibleDDD\Symfony\Runtime\CompiledSubscriptionRegistry;
use TangibleDDD\Symfony\Runtime\DddRuntimeReset;
use TangibleDDD\Symfony\Runtime\DddSignal;
use TangibleDDD\Symfony\Runtime\DeliveryNotes;
use TangibleDDD\Symfony\Runtime\ExplicitHandlerMapping;
use TangibleDDD\Symfony\Runtime\Factory;
use TangibleDDD\Symfony\Runtime\HostDefaultsInstaller;
use TangibleDDD\Symfony\Runtime\LazyProcessEntry;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Symfony\Runtime\SubscriptionProbe;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Symfony\Runtime\SymfonySignalDispatcher;
use TangibleDDD\Symfony\Runtime\UnheardFactNotes;
use TangibleDDD\Symfony\Runtime\Wakeup\PostgresListenWaiter;
use TangibleDDD\Symfony\Runtime\Wakeup\PostgresNotifyRelayWakeup;
use TangibleDDD\Symfony\Runtime\Wakeup\ProcessRunnerWakeTarget;
use TangibleDDD\Symfony\Runtime\Wakeup\WakeupRelay;
use TangibleDDD\Symfony\Workflow\WorkflowContinuations;
use TangibleDDD\Symfony\Workflow\WorkflowWakeTarget;

/**
 * tangible/ddd-symfony services. Every ddd port of a consumer is bound to
 * that consumer's ONE DBAL connection. Service ids are the stable wiring
 * surface; since wave 3 the act bracket, integration bus and relay ids point
 * at the core classes (CR sf-6).
 *
 * Wave 5, several consumers (`tangible_ddd.consumers`): each consumer gets
 * the per-consumer set below; the primary consumer keeps today's ids
 * (`tangible_ddd.outbox_store`, ...) and the port aliases, every other one
 * is `tangible_ddd.consumer.{name}.*` (ConsumerSettings::id()). Shared by
 * all: the clock, actors, audit sink and policy, the domain-event
 * dispatcher, the unit of work, the handler locators, the signal
 * dispatcher, the worker reset and the console commands. With one consumer
 * the container is the one it always was.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
  /** @var array<string, mixed> $config */
  $config = $builder->getParameter('tangible_ddd.config');
  /** @var list<array<string, mixed>> $consumers */
  $consumers = $builder->getParameter('tangible_ddd.consumers');
  $single = count($consumers) === 1;
  $primary = $consumers[0];
  $logger = service('logger')->nullOnInvalid();
  $process = $config['process'];
  $bus = $config['messenger']['bus'];
  $failureTransport = $config['messenger']['failure_transport'];
  $console = class_exists(\Symfony\Component\Console\Command\Command::class);

  $s = $container->services();
  $s->defaults()->private();

  // ── shared: clock, entity manager, actors, audit, pipeline pieces ───────
  $s->set('tangible_ddd.clock', SystemClock::class);
  $s->alias(IClock::class, 'tangible_ddd.clock');

  // L6: the configured EntityManager is flushed before COMMIT and cleared (or,
  // when a failed flush closed it, reset through the `doctrine` registry) after
  // every rollback. No ORM dependency: any service with flush() works. It is
  // bound to the boundaries of the consumers on the primary consumer's connection.
  $entityManager = $config['transaction']['entity_manager'];
  if ($entityManager !== null) {
    $s->set('tangible_ddd.entity_manager_session', EntityManagerSession::class)
      ->args([service($entityManager), service('doctrine')->nullOnInvalid(), $entityManager, $logger]);
  }

  $s->set('tangible_ddd.actor_context', ActorContext::class)->public()->tag('kernel.reset', ['method' => 'reset']);
  $s->alias(ActorContext::class, 'tangible_ddd.actor_context')->public();
  $s->set('tangible_ddd.actor.security', SecurityUserActorProvider::class)
    ->args([service('security.token_storage')->nullOnInvalid()]);
  if ($console) {
    $s->set('tangible_ddd.actor.console', ConsoleOperatorActorProvider::class)
      ->tag('kernel.event_subscriber');
  }
  $s->set('tangible_ddd.actor_provider', SymfonyActorProvider::class)
    ->args([service('tangible_ddd.actor_context'), service('tangible_ddd.actor.security'), $console ? service('tangible_ddd.actor.console') : null]);
  $s->alias(IActorProvider::class, 'tangible_ddd.actor_provider');

  $s->set(EventsUnitOfWork::class)->public();

  $s->set('tangible_ddd.domain_dispatcher', IDomainEventDispatcher::class)
    ->factory([Factory::class, 'dispatcher'])
    ->args([[], abstract_arg('domain listener locator, set by DomainListenerPass')]);

  $s->set('tangible_ddd.audit.sink', NullAuditSink::class);
  // D12 (CR-W4CE-3): #[Audit(false)] / #[Audit(parameters: false)] plus the
  // configured class lists. Bind audit.policy to AuditEverything for the old behaviour.
  $s->set('tangible_ddd.audit.policy', AttributeAuditPolicy::class)
    ->args([
      array_values($config['audit']['not_audited'] ?? []),
      array_values($config['audit']['without_parameters'] ?? []),
    ]);

  // Signals reach the PSR logger and the event dispatcher (HostDefaults at boot).
  $s->set('tangible_ddd.signal_dispatcher', SymfonySignalDispatcher::class)
    ->args([$logger, service('event_dispatcher')->nullOnInvalid()]);

  // The naming convention, plus an explicit map for library commands outside a
  // Commands namespace (core's stranded repairs, WP8-10; set by the bundle).
  $s->set('tangible_ddd.handler_mapping', ExplicitHandlerMapping::class)
    ->args([
      param('tangible_ddd.explicit_handlers'),
      inline_service(MapByNamingConvention::class)->args([inline_service(HandlerClassNameInflector::class), inline_service(Handle::class)]),
    ]);
  $s->set('tangible_ddd.middleware.command_handler', CommandHandlerMiddleware::class)
    ->args([abstract_arg('command handler locator, set by HandlerLocatorPass'), service('tangible_ddd.handler_mapping')]);
  $s->set('tangible_ddd.middleware.query_handler', CommandHandlerMiddleware::class)
    ->args([abstract_arg('query handler locator, set by HandlerLocatorPass'), service('tangible_ddd.handler_mapping')]);

  // ── per consumer ────────────────────────────────────────────────────────
  $wire = static function (array $c) use ($s, $config, $process, $bus, $failureTransport, $logger, $entityManager, $primary, $consumers): void {
    $id = static fn (string $service): string => ConsumerSettings::id($c, $service);
    $tables = $c['tables'];
    $prefix = $c['prefix'];
    $listen = $config['relay']['listen'];

    // connection, consumer
    $s->alias($id('connection'), $c['connection_service']);
    $s->set($id('consumer_config'), SymfonyConsumerConfig::class)
      ->args([$prefix, $c['namespace_root'], $c['version'], $tables])
      ->public();

    // persistence (register 3.2, 3.4, 3.5)
    $em = $entityManager !== null && $c['connection_service'] === $primary['connection_service'];
    $s->set($id('transaction_boundary'), DbalTransactionBoundary::class)
      ->args([
        service($id('connection')),
        $config['transaction']['nested'] === 'savepoint' ? NestedPolicy::Savepoint : NestedPolicy::Reject,
        $em ? [service('tangible_ddd.entity_manager_session'), 'flush'] : null,
        $logger,
        $em ? [service('tangible_ddd.entity_manager_session'), 'reset'] : null,
      ]);

    $s->set($id('relay_pauses'), DbalRelayPauseStore::class)
      ->args([service($id('connection')), $tables]);

    // D14: a transactional NOTIFY per outbox append / wakeup intent; ddd:relay LISTENs.
    $s->set($id('relay_wakeup'), PostgresNotifyRelayWakeup::class)
      ->args([service($id('connection')), $logger]);

    $s->set($id('outbox_store'), DbalPostgresOutboxStore::class)
      ->args([service($id('connection')), service($id('relay_pauses')), $tables, $logger,
        $listen ? service($id('relay_wakeup')) : null, $prefix]);

    $s->set($id('outbox_administration'), DbalOutboxAdministration::class)
      ->args([service($id('connection')), service('tangible_ddd.clock'), $tables]);

    $s->set($id('delivery_ledger'), DbalDeliveryLedger::class)
      ->args([service($id('connection')), $tables]);

    // D1: the effect journal on the domain connection (invalidate commits with the repair command).
    $s->set($id('effect_journal'), DbalEffectJournal::class)
      ->args([service($id('connection')), service('tangible_ddd.clock'), $tables]);

    $s->set($id('outbox_config'), OutboxConfig::class)
      ->factory([Factory::class, 'outbox_config'])
      ->args([$config['relay']]);

    // processes (register 3.6-3.8, 5.2, 5.3)
    $s->set($id('process_store'), DbalProcessStore::class)
      ->args([service($id('connection')), service('tangible_ddd.clock'), $tables, $process['stranded_after_seconds']]);

    // AW2 (wave 5): ICarriesFacts, so a resume that cannot take the process
    // lock is parked as a fact-carrying ResumeRetry instead of failing its
    // delivery (schema 011 `fact`).
    $s->set($id('wakeup_scheduler'), DbalParkingScheduler::class)
      ->args([service($id('connection')), $tables, $listen ? service($id('relay_wakeup')) : null]);

    $s->set($id('process_lock'), ReentrantProcessLock::class)
      ->factory([Factory::class, 'process_lock'])
      ->args([service($id('connection')), $process['pooled_connection'], $logger]);

    // start(): persist + Continue intent in the caller's transaction (X3); the
    // first step runs in a worker unless tangible_ddd.process.inband_start.
    $s->set($id('process_runner'), ProcessRunner::class)
      ->factory([Factory::class, 'process_runner'])
      ->args([
        service($id('consumer_config')),
        service($id('process_lock')),
        service($id('process_store')),
        service($id('wakeup_scheduler')),
        service($id('subscriptions')),
        service($id('transaction_boundary')),
        service('tangible_ddd.clock'),
        $process['inband_start'],
        $logger,
      ])
      ->public();

    $s->set($id('process_entry'), LazyProcessEntry::class)
      ->args([service_closure($c['primary'] && $config['process_entry'] !== null ? $config['process_entry'] : $id('process_runner'))]);

    // The Messenger projection of an intent does not carry a parked fact;
    // ParkedFacts reads it back from the intent row before the runner wakes.
    $s->set($id('process_wake_target'), ParkedFacts::class)
      ->args([
        service($id('wakeup_scheduler')),
        inline_service(ProcessRunnerWakeTarget::class)->args([service($id('process_runner'))]),
      ]);
    // W1: workflow continuations share the wakeup intents; the rest go to the runner.
    $s->set($id('workflow_continuations'), WorkflowContinuations::class)
      ->args([service($id('wakeup_scheduler')), service($id('transaction_boundary')), service('tangible_ddd.clock'), $prefix]);
    $s->set($id('wake_target'), WorkflowWakeTarget::class)
      ->args([
        abstract_arg('IContinuesWorkflows locator, set by HandlerLocatorPass'),
        service($id('workflow_repository')),
        service($id('workflow_continuations')),
        service($id('process_wake_target')),
        service($id('process_lock')),
        $prefix,
        $logger,
      ]);
    $wakeups = $s->set($id('wakeup_handler'), ProcessWakeupHandler::class)
      ->args([service($id('wake_target')), service($id('wakeup_scheduler')), service('tangible_ddd.clock'), $logger]);
    if (count($consumers) === 1) {
      $wakeups->tag('messenger.message_handler', ['bus' => $bus, 'handles' => ProcessWakeupMessage::class]);
    }

    $s->set($id('wakeup_relay'), WakeupRelay::class)
      ->args([
        service($id('wakeup_scheduler')),
        service($id('process_store')),
        service($id('transaction_boundary')),
        service('messenger.transport.' . $c['wakeup_transport']),
        service('tangible_ddd.clock'),
        $prefix,
        $process['wakeup_lease_seconds'],
        $process['stranded_scan_seconds'],
        $logger,
        $bus,
      ]);

    // D10 workflow stores and ignition (ruling #78; core WorkflowIgniter)
    $s->set($id('workflow_repository'), DbalBehaviourWorkflowRepository::class)
      ->args([service(EventsUnitOfWork::class), service($id('connection')), $tables]);
    $s->set($id('work_item_repository'), DbalWorkItemRepository::class)
      ->args([service($id('connection')), $tables]);
    $s->set($id('workflow_ignitions'), DbalWorkflowIgnitionLedger::class)
      ->args([service($id('connection')), $tables, service('tangible_ddd.clock')]);
    // Claim + save + attach in one boundary run; IStartsFromFact services
    // (tag tangible_ddd.workflow) get one ignition subscriber per #[StartsOn] fact.
    $s->set($id('workflow_igniter'), WorkflowIgniter::class)
      ->args([service($id('workflow_ignitions')), service($id('transaction_boundary')), $logger, service('tangible_ddd.clock'),
        $config['workflow']['stale_claim_seconds'], $config['workflow']['stale_start_seconds']]); // W3

    // subscriptions and delivery (register 3.5, D2)
    // AW3, E3: unheard resumes and sent failure commands, noted on the ledger.
    $s->set($id('delivery_notes'), DeliveryNotes::class)
      ->args([service($id('delivery_ledger')), $logger]);
    $s->set($id('subscriptions'), CompiledSubscriptionRegistry::class)
      ->args([[], abstract_arg('listener locator, set by SubscriptionMapPass'), service($id('process_entry')), service($id('workflow_igniter')),
        service($id('delivery_notes'))]);

    $s->set($id('delivery'), IntegrationDelivery::class)
      ->factory([Factory::class, 'delivery'])
      ->args([service($id('subscriptions')), service($id('delivery_ledger')), $c['delivery']['budget'], $logger]);

    $facts = $s->set($id('fact_handler'), IntegrationFactHandler::class)
      ->args([service($id('delivery')), $prefix]);
    if (count($consumers) === 1) {
      $facts->tag('messenger.message_handler', ['bus' => $bus, 'handles' => IntegrationFactMessage::class]);
    }

    $s->set($id('fact_class_resolver'), OutboxFactClassResolver::class)
      ->args([service($id('outbox_store')), []]);

    // Wave 5: a copy of a fact goes to every other consumer that subscribes to it.
    $audiences = [];
    foreach ($consumers as $other) {
      if ($other['name'] !== $c['name']) {
        $audiences[] = inline_service(FactAudience::class)->args([
          $other['prefix'],
          service(ConsumerSettings::id($other, 'subscriptions')),
          service('messenger.transport.' . $other['transport']),
        ]);
      }
    }
    $s->set($id('fact_transport'), MessengerFactTransport::class)
      ->args([
        service('messenger.transport.' . $c['transport']),
        $prefix,
        service($id('fact_class_resolver')),
        $bus,
        service('tangible_ddd.clock'),
        null,
        $audiences,
      ]);

    // AW3: FactDeliveredUnheard for a fact no consumer's map takes.
    $s->set($id('subscriber_probe'), SubscriptionProbe::class)
      ->args([
        array_map(static fn (array $any) => service(ConsumerSettings::id($any, 'subscriptions')), $consumers),
        inline_service(\Closure::class)->factory([\Closure::class, 'fromCallable'])->args([[service($id('fact_class_resolver')), 'class_of_action']]),
      ]);

    // The core relay step (OutboxProcessor port form, CONF-3) behind the stable id.
    $s->set($id('relay'), Relay::class)
      ->args([
        service($id('outbox_store')),
        service($id('fact_transport')),
        service($id('transaction_boundary')),
        service('tangible_ddd.clock'),
        service($id('outbox_config')),
        $logger,
        service($id('consumer_config')),
        service($id('subscriber_probe')),
      ]);

    // D9 operator view (register 3.10, 5.1): core PortOperatorView over the
    // outbox DLQ and stranded processes, plus the sf sources. With several
    // consumers each has its own, merged by ConsumersOperatorView.
    $s->set(count($consumers) === 1 ? 'tangible_ddd.operator_view' : "tangible_ddd.consumer.{$c['name']}.operator_view", PortOperatorView::class)
      ->args([
        service($id('consumer_config')),
        service($id('outbox_administration')),
        service($id('process_store')),
        service('tangible_ddd.clock'),
        [
          inline_service(DbalLedgerOperatorSource::class)
            ->args([service($id('connection')), $prefix, $tables, $c['delivery']['budget']]),
          inline_service(DbalWakeupOperatorSource::class)
            ->args([service($id('connection')), $prefix, $tables]),
          inline_service(DbalUnheardFactSource::class)
            ->args([service($id('connection')), $prefix, $tables]),
          inline_service(DbalWorkflowOperatorSource::class) // W5
            ->args([service($id('connection')), $prefix, $tables, $config['workflow']['stale_start_seconds'], null, service('tangible_ddd.clock')]),
          inline_service(UnrecordedEffects::class) // E2: performed, never recorded (repair ddd:ops:effects:invalidate)
            ->args([service($id('effect_journal')), $prefix, service('tangible_ddd.clock')]),
          inline_service(MessengerFailureTransportSource::class)
            ->args([
              $failureTransport === null || $failureTransport === '' ? null : service('messenger.transport.' . $failureTransport)->nullOnInvalid(),
              $prefix,
              (string) $failureTransport,
            ]),
        ],
      ]);

    // command pipeline (frozen order: act → effect → tx → events → self → handler)
    // The core OutboxIntegrationEventBus (port form, CONF-2) behind the sf
    // decorator that records the fact class on the outbox row (CR sf-1).
    $s->set($id('integration_bus'), IIntegrationEventBus::class)
      ->factory([Factory::class, 'integration_bus'])
      ->args([service($id('outbox_store')), service('tangible_ddd.clock'), service($id('consumer_config')), service($id('outbox_config'))]);

    $s->set($id('event_router'), EventRouter::class)
      ->args([service('tangible_ddd.domain_dispatcher'), service($id('integration_bus'))]);

    $s->set($id('audit.environment'), PhpEnvironmentProvider::class)
      ->factory([Factory::class, 'environment'])
      ->args([param('kernel.environment'), $c['version']]);

    // The act bracket: core CorrelationMiddleware with the audit ports (CONF-1).
    $s->set($id('middleware.act_bracket'), CorrelationMiddleware::class)
      ->args([
        service($id('consumer_config')),
        service(EventsUnitOfWork::class),
        inline_service(Redactor::class),
        service($config['audit']['sink'] ?? 'tangible_ddd.audit.sink'),
        service('tangible_ddd.actor_provider'),
        service($config['audit']['policy'] ?? 'tangible_ddd.audit.policy'),
        service($id('audit.environment')),
      ]);

    // D1: between the act bracket and the transaction (register 3.11): perform()
    // outside any transaction, journaled; record() through the transaction.
    // E1 (wave 5): a handler-class effect's IExternalEffectHandler comes from
    // the command handler locator, named by the bundle's handler mapping.
    $s->set($id('middleware.effect'), EffectMiddleware::class)
      ->args([
        service($id('effect_journal')),
        service($id('transaction_boundary')),
        abstract_arg('command handler locator, set by EffectHandlersPass'),
        service('tangible_ddd.handler_mapping'),
      ]);
    $s->set($id('middleware.transaction'), TransactionalCommandMiddleware::class)
      ->args([service($id('transaction_boundary'))]);
    $s->set($id('middleware.domain_events'), DomainEventsPublishMiddleware::class)
      ->args([service(EventsUnitOfWork::class), service($id('event_router'))]);
    $s->set($id('middleware.self_executing'), SelfExecutingCommandMiddleware::class)
      ->args([abstract_arg('handle() dependency locator, set by HandlerLocatorPass')]);

    $s->set($id('command_bus'), CommandBus::class)
      ->args([
        service($id('middleware.act_bracket')),
        service($id('middleware.effect')),
        service($id('middleware.transaction')),
        service($id('middleware.domain_events')),
        service($id('middleware.self_executing')),
        service('tangible_ddd.middleware.command_handler'),
      ])
      ->public();

    // No act bracket on queries (they are reads, not moments).
    $s->set($id('query_bus'), CommandBus::class)
      ->args([service($id('middleware.self_executing')), service('tangible_ddd.middleware.query_handler')])
      ->public();

    // What ConsumerRegistry hands CommandBusAware / QueryBusAware::send().
    $s->set($id('consumer_container'))
      ->class(\Symfony\Component\DependencyInjection\ServiceLocator::class)
      ->args([[
        CommandBus::class => service_closure($id('command_bus')),
        'tactician.query_bus' => service_closure($id('query_bus')),
        EventsUnitOfWork::class => service_closure(EventsUnitOfWork::class),
      ]])
      ->tag('container.service_locator')
      ->public();
  };
  foreach ($consumers as $c) {
    $wire($c);
  }

  // ── the primary consumer's ports by interface (autowiring) ──────────────
  $s->alias(ITransactionBoundary::class, 'tangible_ddd.transaction_boundary');
  $s->alias(IRelayPauseStore::class, 'tangible_ddd.relay_pauses');
  $s->alias(IRelayWakeup::class, 'tangible_ddd.relay_wakeup');
  $s->alias(IOutboxStore::class, 'tangible_ddd.outbox_store');
  $s->alias(IOutboxAdministration::class, 'tangible_ddd.outbox_administration');
  $s->alias(IDeliveryLedger::class, 'tangible_ddd.delivery_ledger');
  $s->alias(IEffectJournal::class, 'tangible_ddd.effect_journal');
  $s->alias(ITracksEffectState::class, 'tangible_ddd.effect_journal');
  $s->alias(IProcessStore::class, 'tangible_ddd.process_store');
  $s->alias(IWakeupScheduler::class, 'tangible_ddd.wakeup_scheduler');
  $s->alias(IProcessLock::class, 'tangible_ddd.process_lock');
  $s->alias(ProcessRunner::class, 'tangible_ddd.process_runner')->public();
  $s->alias(WorkflowContinuations::class, 'tangible_ddd.workflow_continuations');
  $s->alias(IBehaviourWorkflowRepository::class, 'tangible_ddd.workflow_repository');
  $s->alias(IWorkItemRepository::class, 'tangible_ddd.work_item_repository');
  $s->alias(DbalWorkflowIgnitionLedger::class, 'tangible_ddd.workflow_ignitions');
  $s->alias(IWorkflowIgnitionLedger::class, 'tangible_ddd.workflow_ignitions');
  $s->alias(WorkflowIgniter::class, 'tangible_ddd.workflow_igniter');
  $s->alias(ISubscriptionRegistry::class, 'tangible_ddd.subscriptions');
  $s->alias(ITransport::class, 'tangible_ddd.fact_transport');
  $s->alias(IIntegrationEventBus::class, 'tangible_ddd.integration_bus');
  $s->alias(CommandBus::class, 'tangible_ddd.command_bus')->public();
  $s->alias('tactician.query_bus', 'tangible_ddd.query_bus')->public();

  // ── several consumers: one Messenger handler per message class, one view ─
  if (!$single) {
    $s->set('tangible_ddd.fact_router', ConsumerRouter::class)
      ->args([service_locator(array_combine(
        array_column($consumers, 'prefix'),
        array_map(static fn (array $c) => service(ConsumerSettings::id($c, 'fact_handler')), $consumers),
      ))])
      ->tag('messenger.message_handler', ['bus' => $bus, 'handles' => IntegrationFactMessage::class]);
    $s->set('tangible_ddd.wakeup_router', ConsumerRouter::class)
      ->args([service_locator(array_combine(
        array_column($consumers, 'prefix'),
        array_map(static fn (array $c) => service(ConsumerSettings::id($c, 'wakeup_handler')), $consumers),
      ))])
      ->tag('messenger.message_handler', ['bus' => $bus, 'handles' => ProcessWakeupMessage::class]);
    $s->set('tangible_ddd.operator_view', ConsumersOperatorView::class)
      ->args([array_combine(
        array_column($consumers, 'name'),
        array_map(static fn (array $c) => service("tangible_ddd.consumer.{$c['name']}.operator_view"), $consumers),
      )]);
  }
  $s->alias(IOperatorView::class, 'tangible_ddd.operator_view');

  // AW3: the relay's FactDeliveredUnheard, noted on the raiser's outbox row.
  $s->set('tangible_ddd.unheard_notes', UnheardFactNotes::class)
    ->args([service_locator(array_combine(
      array_column($consumers, 'prefix'),
      array_map(static fn (array $c) => service(ConsumerSettings::id($c, 'outbox_store')), $consumers),
    )), $logger])
    ->tag('kernel.event_listener', ['event' => DddSignal::class, 'method' => '__invoke']);

  // W2 (CR-W5CC-4): the one behaviour type registry, from the compiled map
  // (BehaviourTypePass); the bundle provides it to core at boot and hands
  // the include-time registrations over to it.
  $s->set('tangible_ddd.behaviour_types', BehaviourTypes::class)
    ->args([param('tangible_ddd.behaviour_types')])
    ->public();
  $s->alias(IBehaviourTypes::class, 'tangible_ddd.behaviour_types');

  $s->set('tangible_ddd.host_defaults', HostDefaultsInstaller::class)
    ->args([
      service('tangible_ddd.signal_dispatcher'),
      service('tangible_ddd.clock'),
      service('tangible_ddd.connection'),
      $process['inband_start'],
      $logger,
      param('tangible_ddd.behaviour_types'), // W2, set by BehaviourTypePass
    ])
    ->public();

  // ── worker reset, console ───────────────────────────────────────────────
  $s->set('tangible_ddd.runtime_reset', DddRuntimeReset::class)
    ->args([service(EventsUnitOfWork::class), service('tangible_ddd.actor_context'), $logger])
    ->tag('kernel.event_subscriber')
    ->tag('kernel.reset', ['method' => 'reset'])
    ->public();

  if (!$console) {
    return;
  }
  if ($config['relay']['listen']) {
    $s->set('tangible_ddd.relay_waiter', PostgresListenWaiter::class)
      ->args([service('tangible_ddd.connection'), $single ? $primary['prefix'] : array_column($consumers, 'prefix'), $logger, PoolerPolicy::from($process['pooled_connection'])]);
  }
  $lanes = [];
  if (!$single) {
    foreach ($consumers as $c) {
      $lanes[$c['name']] = [service(ConsumerSettings::id($c, 'relay')), service(ConsumerSettings::id($c, 'wakeup_relay'))];
    }
  }
  $s->set('tangible_ddd.command.relay', RelayCommand::class)
    ->args([
      service('tangible_ddd.relay'), $config['relay']['batch_size'], $config['relay']['idle_sleep_seconds'], $logger, null, 10,
      service('tangible_ddd.wakeup_relay'),
      $config['relay']['listen'] ? service('tangible_ddd.relay_waiter') : null,
      $lanes,
    ])
    ->tag('console.command', ['command' => 'ddd:relay']);
  // ── ddd:ops:* (register 3.10, 5.1); the repairs act on the primary consumer ──
  $s->set('tangible_ddd.command.ops.list', OpsListCommand::class)
    ->args([service('tangible_ddd.operator_view')])
    ->tag('console.command', ['command' => 'ddd:ops:list']);
  $s->set('tangible_ddd.command.ops.dlq_discard', DlqDiscardCommand::class)
    ->args([service('tangible_ddd.outbox_administration')])
    ->tag('console.command', ['command' => 'ddd:ops:dlq:discard']);
  $s->set('tangible_ddd.command.ops.dlq_list', DlqListCommand::class)
    ->args([service('tangible_ddd.outbox_administration')])
    ->tag('console.command', ['command' => 'ddd:ops:dlq:list']);
  $s->set('tangible_ddd.command.ops.dlq_replay', DlqReplayCommand::class)
    ->args([service('tangible_ddd.outbox_administration')])
    ->tag('console.command', ['command' => 'ddd:ops:dlq:replay']);
  $s->set('tangible_ddd.command.ops.dlq_retry', DlqRetryCommand::class)
    ->args([service('tangible_ddd.outbox_administration')])
    ->tag('console.command', ['command' => 'ddd:ops:dlq:retry']);
  $s->set('tangible_ddd.command.ops.stranded', StrandedCommand::class)
    ->args([
      service('tangible_ddd.process_store'), service('tangible_ddd.wakeup_scheduler'), service('tangible_ddd.transaction_boundary'),
      service('tangible_ddd.process_lock'), service('tangible_ddd.clock'), $primary['prefix'], 1.0,
      // WP8-10: core's repair commands on the command bus once they exist (runtime class_exists guard).
      inline_service(CoreStrandedRepairs::class)->args([[service('tangible_ddd.command_bus'), 'handle'], CoreStrandedRepairs::RESUME, CoreStrandedRepairs::FAIL, $primary['prefix']]),
    ])
    ->tag('console.command', ['command' => 'ddd:ops:stranded']);
  $s->set('tangible_ddd.command.ops.effects_invalidate', EffectsInvalidateCommand::class)
    ->args([service('tangible_ddd.effect_journal'), service('tangible_ddd.transaction_boundary')])
    ->tag('console.command', ['command' => 'ddd:ops:effects:invalidate']);
  $s->set('tangible_ddd.command.ops.pause', PauseCommand::class)
    ->args([service('tangible_ddd.relay_pauses'), service('tangible_ddd.clock')])
    ->tag('console.command', ['command' => 'ddd:ops:pause']);
  $s->set('tangible_ddd.command.ops.resume', ResumeCommand::class)
    ->args([service('tangible_ddd.relay_pauses')])
    ->tag('console.command', ['command' => 'ddd:ops:resume']);

  $s->set('tangible_ddd.command.schema_dump', SchemaDumpCommand::class)
    ->args([$primary['tables'], array_combine(array_column($consumers, 'name'), array_column($consumers, 'tables'))])
    ->tag('console.command', ['command' => 'ddd:schema:dump']);
};
