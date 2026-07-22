<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Module;

use League\Tactician\CommandBus;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\CQRS\SelfExecutingCommandMiddleware;
use TangibleDDD\Application\Events\DomainEventsPublishMiddleware;
use TangibleDDD\Infra\Consumers\ConsumerRegistry;
use TangibleDDD\Infra\DependencyInjection\DDDCompilerPasses;
use TangibleDDD\Infra\IDDDConfig;

final class ModuleContainerFactory
{
    public function build(ModuleDefinition $module): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $host_services = [
            IDDDConfig::class => ConsumerRegistry::config_for($module->host_prefix),
            CorrelationMiddleware::class => ConsumerRegistry::service_for(
                $module->host_prefix,
                CorrelationMiddleware::class,
            ),
            $module->transaction_service_id => ConsumerRegistry::service_for(
                $module->host_prefix,
                $module->transaction_service_id,
            ),
            DomainEventsPublishMiddleware::class => ConsumerRegistry::service_for(
                $module->host_prefix,
                DomainEventsPublishMiddleware::class,
            ),
        ];
        foreach ($module->bridged_services as $service_id) {
            $host_services[$service_id] = ConsumerRegistry::service_for($module->host_prefix, $service_id);
        }
        foreach ($host_services as $service_id => $service) {
            $container->register($service_id, $service::class)
                ->setSynthetic(true)
                ->setPublic(true);
        }

        $container->register(SelfExecutingCommandMiddleware::class)
            ->setArguments([new Reference('service_container')]);
        $container->register(CommandBus::class)
            ->setPublic(true)
            ->setArguments([
                new Reference(CorrelationMiddleware::class),
                new Reference($module->transaction_service_id),
                new Reference(DomainEventsPublishMiddleware::class),
                new Reference(SelfExecutingCommandMiddleware::class),
            ]);

        foreach ($module->services as $class) {
            $container->register($class, $class)
                ->setAutowired(true)
                ->setPublic(true);
        }

        foreach ($module->processes as $class) {
            $container->register($class, $class)
                ->setAutowired(false)
                ->setShared(false)
                ->setPublic(false)
                ->addTag('ddd.long_process');
        }

        DDDCompilerPasses::register($container);
        $container->compile();

        foreach ($host_services as $service_id => $service) {
            $container->set($service_id, $service);
        }

        return $container;
    }
}
