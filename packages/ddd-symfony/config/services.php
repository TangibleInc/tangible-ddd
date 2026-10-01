<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use League\Tactician\CommandBus;
use League\Tactician\Handler\CommandHandlerMiddleware;
use League\Tactician\Handler\Mapping\MapByNamingConvention;
use League\Tactician\Handler\Mapping\MethodName\Handle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
use TangibleDDD\Runtime\Audit\AuditEverything;
use TangibleDDD\Runtime\Audit\IActorProvider;
use TangibleDDD\Runtime\Audit\NullAuditSink;
use TangibleDDD\Runtime\Audit\PhpEnvironmentProvider;
use TangibleDDD\Runtime\Delivery\IDeliveryLedger;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\ITransport;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\ITransactionBoundary;
use TangibleDDD\Runtime\NestedPolicy;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Runtime\SystemClock;
use TangibleDDD\Symfony\Console\RelayCommand;
use TangibleDDD\Symfony\Console\SchemaDumpCommand;
use TangibleDDD\Symfony\Messenger\IntegrationFactHandler;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Messenger\MessengerFactTransport;
use TangibleDDD\Symfony\Messenger\OutboxFactClassResolver;
use TangibleDDD\Symfony\Persistence\DbalDeliveryLedger;
use TangibleDDD\Symfony\Persistence\DbalOutboxAdministration;
use TangibleDDD\Symfony\Persistence\DbalPostgresOutboxStore;
use TangibleDDD\Symfony\Persistence\DbalRelayPauseStore;
use TangibleDDD\Symfony\Persistence\DbalTransactionBoundary;
use TangibleDDD\Symfony\Runtime\Actor\ActorContext;
use TangibleDDD\Symfony\Runtime\Actor\ConsoleOperatorActorProvider;
use TangibleDDD\Symfony\Runtime\Actor\SecurityUserActorProvider;
use TangibleDDD\Symfony\Runtime\Actor\SymfonyActorProvider;
use TangibleDDD\Symfony\Runtime\CompiledSubscriptionRegistry;
use TangibleDDD\Symfony\Runtime\DddRuntimeReset;
use TangibleDDD\Symfony\Runtime\Factory;
use TangibleDDD\Symfony\Runtime\Relay;
use TangibleDDD\Symfony\Runtime\SymfonyConsumerConfig;
use TangibleDDD\Symfony\Runtime\SymfonySignalDispatcher;
use TangibleDDD\Symfony\Runtime\HostDefaultsInstaller;
use TangibleDDD\Symfony\Runtime\LazyProcessEntry;
use TangibleDDD\Symfony\Runtime\Wakeup\PostgresListenWaiter;
use TangibleDDD\Symfony\Runtime\Wakeup\PostgresNotifyRelayWakeup;
use TangibleDDD\Symfony\Runtime\Wakeup\ProcessRunnerWakeTarget;
use TangibleDDD\Symfony\Runtime\Wakeup\WakeupRelay;
use TangibleDDD\Symfony\Messenger\ProcessWakeupHandler;
use TangibleDDD\Symfony\Messenger\ProcessWakeupMessage;
use TangibleDDD\Symfony\Persistence\DbalProcessStore;
use TangibleDDD\Symfony\Persistence\DbalBehaviourWorkflowRepository;
use TangibleDDD\Symfony\Persistence\DbalWorkItemRepository;
use TangibleDDD\Symfony\Persistence\DbalWorkflowIgnitionLedger;
use TangibleDDD\Domain\Repositories\IBehaviourWorkflowRepository;
use TangibleDDD\Domain\Repositories\IWorkItemRepository;
use TangibleDDD\Symfony\Persistence\DbalWakeupScheduler;
use TangibleDDD\Symfony\Persistence\PoolerPolicy;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Process\ProcessRunner;
use TangibleDDD\Runtime\Delivery\IRelayWakeup;
use TangibleDDD\Runtime\Lock\IProcessLock;
use TangibleDDD\Runtime\Lock\ReentrantProcessLock;
use TangibleDDD\Runtime\Process\IProcessStore;
use TangibleDDD\Runtime\Scheduling\IWakeupScheduler;

/**
 * tangible/ddd-symfony services. Every ddd port is bound to ONE DBAL
 * connection (`tangible_ddd.connection`). Service ids are the stable wiring
 * surface; since wave 3 the act bracket, integration bus and relay ids point
 * at the core classes (CR sf-6).
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
  /** @var array<string, mixed> $config */
  $config = $builder->getParameter('tangible_ddd.config');
  $prefix = $config['table_prefix'];
  $consumer = $config['consumer'];
  $logger = service('logger')->nullOnInvalid();

  $s = $container->services();
  $s->defaults()->private();

  // ── connection, clock ────────────────────────────────────────────────────
  $s->alias('tangible_ddd.connection', $config['connection_service'] ?? sprintf('doctrine.dbal.%s_connection', $config['connection']));
  $s->set('tangible_ddd.clock', SystemClock::class);
  $s->alias(IClock::class, 'tangible_ddd.clock');

  // ── consumer ─────────────────────────────────────────────────────────────
  $s->set('tangible_ddd.consumer_config', SymfonyConsumerConfig::class)
    ->args([$consumer['prefix'], $consumer['namespace_root'], $consumer['version'], $prefix])
    ->public();

  // ── persistence (register 3.2, 3.4, 3.5) ─────────────────────────────────
  $s->set('tangible_ddd.transaction_boundary', DbalTransactionBoundary::class)
    ->args([
      service('tangible_ddd.connection'),
      $config['transaction']['nested'] === 'savepoint' ? NestedPolicy::Savepoint : NestedPolicy::Reject,
      $config['transaction']['entity_manager'] === null ? null : [service($config['transaction']['entity_manager']), 'flush'],
      $logger,
    ]);
  $s->alias(ITransactionBoundary::class, 'tangible_ddd.transaction_boundary');

  $s->set('tangible_ddd.relay_pauses', DbalRelayPauseStore::class)
    ->args([service('tangible_ddd.connection'), $prefix]);
  $s->alias(IRelayPauseStore::class, 'tangible_ddd.relay_pauses');

  // D14: a transactional NOTIFY per outbox append / wakeup intent; ddd:relay LISTENs.
  $s->set('tangible_ddd.relay_wakeup', PostgresNotifyRelayWakeup::class)
    ->args([service('tangible_ddd.connection'), $logger]);
  $s->alias(IRelayWakeup::class, 'tangible_ddd.relay_wakeup');

  $s->set('tangible_ddd.outbox_store', DbalPostgresOutboxStore::class)
    ->args([service('tangible_ddd.connection'), service('tangible_ddd.relay_pauses'), $prefix, $logger,
      $config['relay']['listen'] ? service('tangible_ddd.relay_wakeup') : null, $consumer['prefix']]);
  $s->alias(IOutboxStore::class, 'tangible_ddd.outbox_store');

  $s->set('tangible_ddd.outbox_administration', DbalOutboxAdministration::class)
    ->args([service('tangible_ddd.connection'), service('tangible_ddd.clock'), $prefix]);
  $s->alias(IOutboxAdministration::class, 'tangible_ddd.outbox_administration');

  $s->set('tangible_ddd.delivery_ledger', DbalDeliveryLedger::class)
    ->args([service('tangible_ddd.connection'), $prefix]);
  $s->alias(IDeliveryLedger::class, 'tangible_ddd.delivery_ledger');

  $s->set('tangible_ddd.outbox_config', OutboxConfig::class)
    ->factory([Factory::class, 'outboxConfig'])
    ->args([$config['relay']]);

  // ── processes (register 3.6-3.8, 5.2, 5.3) ───────────────────────────────
  $process = $config['process'];
  $s->set('tangible_ddd.process_store', DbalProcessStore::class)
    ->args([service('tangible_ddd.connection'), service('tangible_ddd.clock'), $prefix, $process['stranded_after_seconds']]);
  $s->alias(IProcessStore::class, 'tangible_ddd.process_store');

  $s->set('tangible_ddd.wakeup_scheduler', DbalWakeupScheduler::class)
    ->args([service('tangible_ddd.connection'), $prefix, $config['relay']['listen'] ? service('tangible_ddd.relay_wakeup') : null]);
  $s->alias(IWakeupScheduler::class, 'tangible_ddd.wakeup_scheduler');

  $s->set('tangible_ddd.process_lock', ReentrantProcessLock::class)
    ->factory([Factory::class, 'processLock'])
    ->args([service('tangible_ddd.connection'), $process['pooled_connection'], $logger]);
  $s->alias(IProcessLock::class, 'tangible_ddd.process_lock');

  // start(): persist + Continue intent in the caller's transaction (X3); the
  // first step runs in a worker unless tangible_ddd.process.inband_start.
  $s->set('tangible_ddd.process_runner', ProcessRunner::class)
    ->factory([Factory::class, 'processRunner'])
    ->args([
      service('tangible_ddd.consumer_config'),
      service('tangible_ddd.process_lock'),
      service('tangible_ddd.process_store'),
      service('tangible_ddd.wakeup_scheduler'),
      service('tangible_ddd.subscriptions'),
      service('tangible_ddd.transaction_boundary'),
      service('tangible_ddd.clock'),
      $process['inband_start'],
      $logger,
    ])
    ->public();
  $s->alias(ProcessRunner::class, 'tangible_ddd.process_runner')->public();

  $s->set('tangible_ddd.process_entry', LazyProcessEntry::class)
    ->args([service_closure($config['process_entry'] ?? 'tangible_ddd.process_runner')]);

  $s->set('tangible_ddd.wake_target', ProcessRunnerWakeTarget::class)
    ->args([service('tangible_ddd.process_runner')]);
  $s->set('tangible_ddd.wakeup_handler', ProcessWakeupHandler::class)
    ->args([service('tangible_ddd.wake_target'), service('tangible_ddd.wakeup_scheduler'), service('tangible_ddd.clock'), $logger])
    ->tag('messenger.message_handler', ['bus' => $config['messenger']['bus'], 'handles' => ProcessWakeupMessage::class]);

  $s->set('tangible_ddd.wakeup_relay', WakeupRelay::class)
    ->args([
      service('tangible_ddd.wakeup_scheduler'),
      service('tangible_ddd.process_store'),
      service('tangible_ddd.transaction_boundary'),
      service('messenger.transport.' . $config['messenger']['wakeup_transport']),
      service('tangible_ddd.clock'),
      $consumer['prefix'],
      $process['wakeup_lease_seconds'],
      $process['stranded_scan_seconds'],
      $logger,
      $config['messenger']['bus'],
    ]);

  // ── D10 workflow stores (ruling #78; core contract wiring is wave 4) ─────
  $s->set('tangible_ddd.workflow_repository', DbalBehaviourWorkflowRepository::class)
    ->args([service(EventsUnitOfWork::class), service('tangible_ddd.connection'), $prefix]);
  $s->alias(IBehaviourWorkflowRepository::class, 'tangible_ddd.workflow_repository');
  $s->set('tangible_ddd.work_item_repository', DbalWorkItemRepository::class)
    ->args([service('tangible_ddd.connection'), $prefix]);
  $s->alias(IWorkItemRepository::class, 'tangible_ddd.work_item_repository');
  $s->set('tangible_ddd.workflow_ignitions', DbalWorkflowIgnitionLedger::class)
    ->args([service('tangible_ddd.connection'), $prefix]);
  $s->alias(DbalWorkflowIgnitionLedger::class, 'tangible_ddd.workflow_ignitions');

  // ── subscriptions and delivery (register 3.5, D2) ────────────────────────
  $s->set('tangible_ddd.subscriptions', CompiledSubscriptionRegistry::class)
    ->args([[], abstract_arg('listener locator, set by SubscriptionMapPass'), service('tangible_ddd.process_entry')]);
  $s->alias(ISubscriptionRegistry::class, 'tangible_ddd.subscriptions');

  $s->set('tangible_ddd.delivery', IntegrationDelivery::class)
    ->factory([Factory::class, 'delivery'])
    ->args([service('tangible_ddd.subscriptions'), service('tangible_ddd.delivery_ledger'), $config['delivery']['budget'], $logger]);

  $s->set('tangible_ddd.fact_handler', IntegrationFactHandler::class)
    ->args([service('tangible_ddd.delivery'), $consumer['prefix']])
    ->tag('messenger.message_handler', ['bus' => $config['messenger']['bus'], 'handles' => IntegrationFactMessage::class]);

  $s->set('tangible_ddd.fact_class_resolver', OutboxFactClassResolver::class)
    ->args([service('tangible_ddd.outbox_store'), []]);

  $s->set('tangible_ddd.fact_transport', MessengerFactTransport::class)
    ->args([
      service('messenger.transport.' . $config['messenger']['transport']),
      $consumer['prefix'],
      service('tangible_ddd.fact_class_resolver'),
      $config['messenger']['bus'],
      service('tangible_ddd.clock'),
    ]);
  $s->alias(ITransport::class, 'tangible_ddd.fact_transport');

  // The core relay step (OutboxProcessor port form, CONF-3) behind the stable id.
  $s->set('tangible_ddd.relay', Relay::class)
    ->args([
      service('tangible_ddd.outbox_store'),
      service('tangible_ddd.fact_transport'),
      service('tangible_ddd.transaction_boundary'),
      service('tangible_ddd.clock'),
      service('tangible_ddd.outbox_config'),
      $logger,
      service('tangible_ddd.consumer_config'),
    ]);

  // ── actors (D5) ──────────────────────────────────────────────────────────
  $s->set('tangible_ddd.actor_context', ActorContext::class)->public()->tag('kernel.reset', ['method' => 'reset']);
  $s->alias(ActorContext::class, 'tangible_ddd.actor_context')->public();
  $s->set('tangible_ddd.actor.security', SecurityUserActorProvider::class)
    ->args([service('security.token_storage')->nullOnInvalid()]);
  $console = class_exists(\Symfony\Component\Console\Command\Command::class);
  if ($console) {
    $s->set('tangible_ddd.actor.console', ConsoleOperatorActorProvider::class)
      ->tag('kernel.event_subscriber');
  }
  $s->set('tangible_ddd.actor_provider', SymfonyActorProvider::class)
    ->args([service('tangible_ddd.actor_context'), service('tangible_ddd.actor.security'), $console ? service('tangible_ddd.actor.console') : null]);
  $s->alias(IActorProvider::class, 'tangible_ddd.actor_provider');

  // ── command pipeline (frozen order: act → tx → events → self → handler) ──
  $s->set(EventsUnitOfWork::class)->public();

  $s->set('tangible_ddd.domain_dispatcher', IDomainEventDispatcher::class)
    ->factory([Factory::class, 'domainDispatcher'])
    ->args([[], abstract_arg('domain listener locator, set by DomainListenerPass')]);

  // The core OutboxIntegrationEventBus (port form, CONF-2) behind the sf
  // decorator that records the fact class on the outbox row (CR sf-1).
  $s->set('tangible_ddd.integration_bus', IIntegrationEventBus::class)
    ->factory([Factory::class, 'integrationBus'])
    ->args([service('tangible_ddd.outbox_store'), service('tangible_ddd.clock'), service('tangible_ddd.consumer_config'), service('tangible_ddd.outbox_config')]);
  $s->alias(IIntegrationEventBus::class, 'tangible_ddd.integration_bus');

  $s->set('tangible_ddd.event_router', EventRouter::class)
    ->args([service('tangible_ddd.domain_dispatcher'), service('tangible_ddd.integration_bus')]);

  $s->set('tangible_ddd.audit.sink', NullAuditSink::class);
  $s->set('tangible_ddd.audit.policy', AuditEverything::class);
  $s->set('tangible_ddd.audit.environment', PhpEnvironmentProvider::class)
    ->args([['env' => param('kernel.environment'), 'app' => $consumer['version']]]);

  // The act bracket: core CorrelationMiddleware with the audit ports (CONF-1).
  $s->set('tangible_ddd.middleware.act_bracket', CorrelationMiddleware::class)
    ->args([
      service('tangible_ddd.consumer_config'),
      service(EventsUnitOfWork::class),
      inline_service(Redactor::class),
      service($config['audit']['sink'] ?? 'tangible_ddd.audit.sink'),
      service('tangible_ddd.actor_provider'),
      service($config['audit']['policy'] ?? 'tangible_ddd.audit.policy'),
      service('tangible_ddd.audit.environment'),
    ]);

  // Signals reach the PSR logger and the event dispatcher (HostDefaults at boot).
  $s->set('tangible_ddd.signal_dispatcher', SymfonySignalDispatcher::class)
    ->args([$logger, service('event_dispatcher')->nullOnInvalid()]);
  $s->set('tangible_ddd.host_defaults', HostDefaultsInstaller::class)
    ->args([
      service('tangible_ddd.signal_dispatcher'),
      service('tangible_ddd.clock'),
      service('tangible_ddd.connection'),
      $config['process']['inband_start'],
      $logger,
    ])
    ->public();
  $s->set('tangible_ddd.middleware.transaction', TransactionalCommandMiddleware::class)
    ->args([service('tangible_ddd.transaction_boundary')]);
  $s->set('tangible_ddd.middleware.domain_events', DomainEventsPublishMiddleware::class)
    ->args([service(EventsUnitOfWork::class), service('tangible_ddd.event_router')]);
  $s->set('tangible_ddd.middleware.self_executing', SelfExecutingCommandMiddleware::class)
    ->args([abstract_arg('handle() dependency locator, set by HandlerLocatorPass')]);

  $s->set('tangible_ddd.handler_mapping', MapByNamingConvention::class)
    ->args([inline_service(HandlerClassNameInflector::class), inline_service(Handle::class)]);
  $s->set('tangible_ddd.middleware.command_handler', CommandHandlerMiddleware::class)
    ->args([abstract_arg('command handler locator, set by HandlerLocatorPass'), service('tangible_ddd.handler_mapping')]);
  $s->set('tangible_ddd.middleware.query_handler', CommandHandlerMiddleware::class)
    ->args([abstract_arg('query handler locator, set by HandlerLocatorPass'), service('tangible_ddd.handler_mapping')]);

  $s->set('tangible_ddd.command_bus', CommandBus::class)
    ->args([
      service('tangible_ddd.middleware.act_bracket'),
      service('tangible_ddd.middleware.transaction'),
      service('tangible_ddd.middleware.domain_events'),
      service('tangible_ddd.middleware.self_executing'),
      service('tangible_ddd.middleware.command_handler'),
    ])
    ->public();
  $s->alias(CommandBus::class, 'tangible_ddd.command_bus')->public();

  // No act bracket on queries (they are reads, not moments).
  $s->set('tangible_ddd.query_bus', CommandBus::class)
    ->args([service('tangible_ddd.middleware.self_executing'), service('tangible_ddd.middleware.query_handler')])
    ->public();
  $s->alias('tactician.query_bus', 'tangible_ddd.query_bus')->public();

  // What ConsumerRegistry hands CommandBusAware / QueryBusAware::send().
  $s->set('tangible_ddd.consumer_container')
    ->class(\Symfony\Component\DependencyInjection\ServiceLocator::class)
    ->args([[
      CommandBus::class => service_closure('tangible_ddd.command_bus'),
      'tactician.query_bus' => service_closure('tangible_ddd.query_bus'),
      EventsUnitOfWork::class => service_closure(EventsUnitOfWork::class),
    ]])
    ->tag('container.service_locator')
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
      ->args([service('tangible_ddd.connection'), $consumer['prefix'], $logger, PoolerPolicy::from($process['pooled_connection'])]);
  }
  $s->set('tangible_ddd.command.relay', RelayCommand::class)
    ->args([
      service('tangible_ddd.relay'), $config['relay']['batch_size'], $config['relay']['idle_sleep_seconds'], $logger, null, 10,
      service('tangible_ddd.wakeup_relay'),
      $config['relay']['listen'] ? service('tangible_ddd.relay_waiter') : null,
    ])
    ->tag('console.command', ['command' => 'ddd:relay']);
  $s->set('tangible_ddd.command.schema_dump', SchemaDumpCommand::class)
    ->args([$prefix])
    ->tag('console.command', ['command' => 'ddd:schema:dump']);
};
