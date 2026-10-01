<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Symfony\DependencyInjection\DddTags;

/**
 * Collects `tangible_ddd.domain_listener` services (in-transaction domain
 * event reactions) into the OrderedListenerDispatcher factory: a list of
 * [event, priority, service id, method] plus a locator, so reactions are
 * built on first dispatch.
 */
final class DomainListenerPass implements CompilerPassInterface {

  public function process(ContainerBuilder $container): void {
    if (!$container->hasDefinition('tangible_ddd.domain_dispatcher')) {
      return;
    }

    $listeners = [];
    $refs = [];
    foreach ($container->findTaggedServiceIds(DddTags::DOMAIN_LISTENER) as $id => $tags) {
      foreach ($tags as $attributes) {
        $event = $attributes['event'] ?? throw new InvalidArgumentException("Domain listener \"$id\" is tagged without an \"event\" attribute.");
        if (!class_exists($event) && !interface_exists($event)) {
          throw new InvalidArgumentException("Domain listener \"$id\" listens to $event, which does not exist.");
        }
        $listeners[] = [$event, (int) ($attributes['priority'] ?? 10), $id, (string) ($attributes['method'] ?? '__invoke')];
      }
      $refs[$id] = new Reference($id);
    }

    $container->getDefinition('tangible_ddd.domain_dispatcher')
      ->replaceArgument(0, $listeners)
      ->replaceArgument(1, ServiceLocatorTagPass::register($container, $refs));
  }
}
