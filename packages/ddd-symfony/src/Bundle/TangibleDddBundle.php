<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Bundle;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use TangibleDDD\Application\CommandHandlers\ICommandHandler;
use TangibleDDD\Application\QueryHandlers\IQueryHandler;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;
use TangibleDDD\Symfony\DependencyInjection\Attribute\AsDomainEventListener;
use TangibleDDD\Symfony\DependencyInjection\Attribute\AsIntegrationListener;
use TangibleDDD\Symfony\DependencyInjection\Compiler\DomainListenerPass;
use TangibleDDD\Symfony\DependencyInjection\Compiler\HandlerLocatorPass;
use TangibleDDD\Symfony\DependencyInjection\Compiler\MessengerHealthPass;
use TangibleDDD\Symfony\DependencyInjection\Compiler\SubscriptionMapPass;
use TangibleDDD\Symfony\DependencyInjection\DddTags;

/**
 * The Symfony host for tangible/ddd-core (register 1.2, 3.2-3.5, 5.1).
 *
 * Wires, on ONE DBAL connection: the transaction boundary, the Postgres
 * outbox, relay pauses, delivery ledger and outbox administration; the
 * Tactician command and query buses in the frozen middleware order
 * (act bracket → transaction → domain events → self-executing → handler)
 * with compiled service locators instead of `@service_container`; the
 * compile-time subscription map; the `ddd_facts` Messenger transport and
 * its delivery handler; the worker reset; D5 actor providers; and the
 * `ddd:relay` / `ddd:schema:dump` commands.
 *
 * At boot it registers the one consumer (E S8) in ConsumerRegistry, so
 * `$command->send()` and `Event::prefix()` resolve by namespace.
 */
final class TangibleDddBundle extends AbstractBundle {

  protected string $extensionAlias = 'tangible_ddd';

  public function getPath(): string {
    return \dirname(__DIR__, 2);
  }

  public function configure(DefinitionConfigurator $definition): void {
    $definition->rootNode()
      ->children()
        ->arrayNode('consumer')
          ->isRequired()
          ->children()
            ->scalarNode('prefix')->isRequired()->cannotBeEmpty()
              ->info('Stable [a-z0-9_]+ consumer prefix; names hooks, integration actions and ledger keys (e.g. "txp").')->end()
            ->scalarNode('namespace_root')->isRequired()->cannotBeEmpty()
              ->info('PHP namespace the consumer owns (e.g. "App"); send() and Event::prefix() resolve by it.')->end()
            ->scalarNode('version')->defaultValue('0.0.0')->end()
            ->scalarNode('label')->defaultNull()->end()
          ->end()
        ->end()
        ->scalarNode('connection')->defaultValue('default')
          ->info('DoctrineBundle DBAL connection name shared by domain repositories, outbox and the ddd_facts transport.')->end()
        ->scalarNode('connection_service')->defaultNull()
          ->info('Explicit DBAL Connection service id (overrides "connection"; for hosts without DoctrineBundle).')->end()
        ->scalarNode('table_prefix')->defaultValue('')->end()
        ->arrayNode('transaction')
          ->addDefaultsIfNotSet()
          ->children()
            ->enumNode('nested')->values(['reject', 'savepoint'])->defaultValue('reject')
              ->info('What a command does when a transaction is already open: reject (production) or savepoint (test wrappers).')->end()
          ->end()
        ->end()
        ->arrayNode('relay')
          ->addDefaultsIfNotSet()
          ->children()
            ->integerNode('batch_size')->defaultValue(50)->min(1)->end()
            ->integerNode('lease_seconds')->defaultValue(300)->min(1)->end()
            ->integerNode('max_attempts')->defaultValue(5)->min(1)->end()
            ->integerNode('base_retry_delay_seconds')->defaultValue(60)->min(0)->end()
            ->floatNode('retry_multiplier')->defaultValue(2.0)->min(1.0)->end()
            ->integerNode('max_retry_delay_seconds')->defaultValue(3600)->min(0)->end()
            ->integerNode('idle_sleep_seconds')->defaultValue(1)->min(0)->end()
          ->end()
        ->end()
        ->arrayNode('delivery')
          ->addDefaultsIfNotSet()
          ->children()
            ->integerNode('budget')->defaultValue(5)->min(1)
              ->info('Handler attempts per (subscriber, fact), counted in the ledger (5.1).')->end()
            ->integerNode('retry_delay_ms')->defaultValue(30000)->min(0)->end()
            ->floatNode('retry_multiplier')->defaultValue(2.0)->min(1.0)->end()
            ->integerNode('max_retry_delay_ms')->defaultValue(3600000)->min(0)->end()
          ->end()
        ->end()
        ->arrayNode('messenger')
          ->addDefaultsIfNotSet()
          ->children()
            ->scalarNode('transport')->defaultValue('ddd_facts')->end()
            ->scalarNode('failure_transport')->defaultValue('ddd_failed')->end()
            ->scalarNode('bus')->defaultValue('messenger.bus.default')
              ->info('Bus the delivery handler lives on; it must not carry doctrine_transaction.')->end()
            ->booleanNode('configure_transports')->defaultTrue()
              ->info('Prepend framework.messenger transports (doctrine://<connection>, retry strategy = delivery budget).')->end()
            ->scalarNode('dsn')->defaultNull()->end()
            ->scalarNode('failure_dsn')->defaultNull()->end()
          ->end()
        ->end()
        ->arrayNode('facts')
          ->scalarPrototype()->end()
          ->info('Extra fact classes for resolving rows written without a class (normally not needed).')
        ->end()
        ->scalarNode('process_entry')->defaultNull()
          ->info('Service id of an IProcessEntry (the process runner, wave 3); needed once a process uses #[StartsOn]/#[Awaits].')->end()
        ->arrayNode('audit')
          ->addDefaultsIfNotSet()
          ->children()
            ->scalarNode('sink')->defaultNull()->info('IAuditSink service id; default NullAuditSink.')->end()
            ->scalarNode('policy')->defaultNull()->info('IAuditPolicy service id; default AuditEverything.')->end()
          ->end()
        ->end()
      ->end();
  }

  public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void {
    $config = (new Processor())->processConfiguration(
      $this->getContainerExtension()->getConfiguration([], $builder),
      $builder->getExtensionConfig($this->extensionAlias)
    );
    if (!$config['messenger']['configure_transports']) {
      return;
    }
    $m = $config['messenger'];
    $d = $config['delivery'];
    $connection = $config['connection'];

    $facts = [
      'dsn' => $m['dsn'] ?? "doctrine://{$connection}?queue_name={$m['transport']}&auto_setup=false",
      'retry_strategy' => [
        'max_retries' => $d['budget'] - 1,
        'delay' => $d['retry_delay_ms'],
        'multiplier' => $d['retry_multiplier'],
        'max_delay' => $d['max_retry_delay_ms'],
      ],
    ];
    $transports = [];
    if ($m['failure_transport'] !== null && $m['failure_transport'] !== '') {
      $facts['failure_transport'] = $m['failure_transport'];
      $transports[$m['failure_transport']] = [
        'dsn' => $m['failure_dsn'] ?? "doctrine://{$connection}?queue_name={$m['failure_transport']}&auto_setup=false",
      ];
    }
    $transports[$m['transport']] = $facts;

    $builder->prependExtensionConfig('framework', ['messenger' => ['transports' => $transports]]);
  }

  public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void {
    $builder->setParameter('tangible_ddd.config', $config);
    $builder->setParameter('tangible_ddd.consumer', $config['consumer']);
    $builder->setParameter('tangible_ddd.table_prefix', $config['table_prefix']);
    $builder->setParameter('tangible_ddd.facts', $config['facts']);
    $builder->setParameter('tangible_ddd.messenger.bus', $config['messenger']['bus']);
    $builder->setParameter('tangible_ddd.messenger.transport', $config['messenger']['transport']);

    $container->import($this->getPath() . '/config/services.php');

    $builder->registerForAutoconfiguration(ICommandHandler::class)->addTag(DddTags::COMMAND_HANDLER);
    $builder->registerForAutoconfiguration(IQueryHandler::class)->addTag(DddTags::QUERY_HANDLER);
    $translator = 'TangibleDDD\\Application\\EventHandlers\\IntegrationTranslator'; // core, wave-2 split
    if (class_exists($translator)) {
      $builder->registerForAutoconfiguration($translator)->addTag(DddTags::INTEGRATION_LISTENER);
    }
    $builder->registerAttributeForAutoconfiguration(
      AsIntegrationListener::class,
      static function (ChildDefinition $definition, AsIntegrationListener $attribute): void {
        $definition->addTag(DddTags::INTEGRATION_LISTENER, array_filter(['event' => $attribute->event]));
      }
    );
    $builder->registerAttributeForAutoconfiguration(
      AsDomainEventListener::class,
      static function (ChildDefinition $definition, AsDomainEventListener $attribute): void {
        $definition->addTag(DddTags::DOMAIN_LISTENER, [
          'event' => $attribute->event,
          'priority' => $attribute->priority,
          'method' => $attribute->method,
        ]);
      }
    );
  }

  public function build(ContainerBuilder $container): void {
    parent::build($container);
    DDDCompilerPasses::register($container);
    $container->addCompilerPass(new HandlerLocatorPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    $container->addCompilerPass(new SubscriptionMapPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    $container->addCompilerPass(new DomainListenerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    $container->addCompilerPass(new MessengerHealthPass(), PassConfig::TYPE_BEFORE_REMOVING);
  }

  public function boot(): void {
    $c = $this->container;
    if ($c === null || !$c->has('tangible_ddd.consumer_config')) {
      return;
    }
    /** @var array{prefix: string, namespace_root: string, version: string, label: ?string} $consumer */
    $consumer = $c->getParameter('tangible_ddd.consumer');
    ConsumerRegistry::add(
      $c->get('tangible_ddd.consumer_config'),
      static fn () => $c->get('tangible_ddd.consumer_container'),
      $consumer['label'],
      trim($consumer['namespace_root'], '\\'),
    );
    $c->get('tangible_ddd.runtime_reset')->install();
  }
}
