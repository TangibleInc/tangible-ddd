<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Module;

use League\Tactician\CommandBus;
use League\Tactician\Handler\CommandHandlerMiddleware;
use League\Tactician\Handler\Mapping\MapByStaticList;
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
        $bus_middleware = [
            new Reference(CorrelationMiddleware::class),
            new Reference($module->transaction_service_id),
            new Reference(DomainEventsPublishMiddleware::class),
            new Reference(SelfExecutingCommandMiddleware::class),
        ];
        if ($module->handlers !== []) {
            // Two-class shape terminal: a plain command passes through the
            // self-executing middleware untouched and resolves its paired
            // handler here, inside the module container (consumer-module
            // rule: terminal command/query resolution stays module-local).
            $container->register(MapByStaticList::class)
                ->setArguments([array_map(
                    static fn (string $handler): array => [$handler, 'handle'],
                    $module->handlers,
                )]);
            $container->register(CommandHandlerMiddleware::class)
                ->setArguments([
                    new Reference('service_container'),
                    new Reference(MapByStaticList::class),
                ]);
            $bus_middleware[] = new Reference(CommandHandlerMiddleware::class);
        }
        $container->register(CommandBus::class)
            ->setPublic(true)
            ->setArguments($bus_middleware);

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
