<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriberPriority;
use TangibleDDD\Symfony\DependencyInjection\DddTags;
use TangibleDDD\Symfony\Runtime\CompiledSubscriptionRegistry;

/**
 * The add_action replacement (E S4, S5; D2): builds the subscription map at
 * compile time.
 *
 * - Listener services tagged `tangible_ddd.integration_listener`: the event
 *   (fact class or marker interface) comes from the tag's `event` attribute,
 *   else from event_class() / get_event_class() on an instance created
 *   WITHOUT its constructor; priority from #[SubscriberPriority] (default
 *   LISTENER = 10). The service goes into a locator: it is constructed at
 *   the first delivery of a matching fact, never at boot.
 * - Processes tagged `ddd.long_process`: each #[StartsOn(E)] is an ignition
 *   (IGNITION = 50), each #[Awaits(E)] a resume (RESUME = 99, one per fact).
 *
 * The specs feed `tangible_ddd.subscriptions` (CompiledSubscriptionRegistry,
 * which builds each Subscriber through the core SubscriptionRegistrar) and
 * the fact-class fallback of `tangible_ddd.fact_class_resolver`. A listener
 * or process naming something that is neither an IIntegrationEvent nor an
 * interface fails compilation.
 */
final class SubscriptionMapPass implements CompilerPassInterface {

  public function process(ContainerBuilder $container): void {
    if (!$container->hasDefinition('tangible_ddd.subscriptions')) {
      return;
    }

    $specs = [];
    $listenerRefs = [];
    $bag = $container->getParameterBag();

    foreach ($container->findTaggedServiceIds(DddTags::INTEGRATION_LISTENER) as $id => $tags) {
      $class = $bag->resolveValue($container->getDefinition($id)->getClass() ?? $id);
      if (!is_string($class) || !class_exists($class)) {
        throw new InvalidArgumentException("Integration listener service \"$id\" has no loadable class.");
      }
      $event = null;
      foreach ($tags as $attributes) {
        $event ??= $attributes['event'] ?? null;
      }
      $event ??= self::eventOf($class, $id);
      self::assertSubscribable($event, $class);

      $priority = Subscriber::LISTENER;
      $attr = (new \ReflectionClass($class))->getAttributes(SubscriberPriority::class);
      if ($attr !== []) {
        $priority = $attr[0]->newInstance()->priority;
      }

      $specs[] = CompiledSubscriptionRegistry::listenerSpec($id, $class, $event, $priority);
      $listenerRefs[$id] = new Reference($id);
    }

    $seen = [];
    foreach ($container->findTaggedServiceIds(DddTags::LONG_PROCESS) as $id => $tags) {
      $class = $bag->resolveValue($container->getDefinition($id)->getClass() ?? $id);
      if (!is_string($class) || !is_subclass_of($class, LongProcess::class)) {
        continue; // LongProcessCatalogPass reports misuse of the tag
      }
      $reflection = new \ReflectionClass($class);
      foreach ($reflection->getAttributes(StartsOn::class) as $a) {
        $event = $a->newInstance()->event_class;
        self::assertSubscribable($event, $class);
        $specs[] = CompiledSubscriptionRegistry::processSpec($class, 'ignition', $event);
      }
      foreach ($reflection->getAttributes(Awaits::class) as $a) {
        $event = $a->newInstance()->event_class;
        self::assertSubscribable($event, $class);
        $spec = CompiledSubscriptionRegistry::processSpec($class, 'resume', $event);
        if (!isset($seen[$spec['id']])) {
          $seen[$spec['id']] = true;
          $specs[] = $spec;
        }
      }
    }

    $container->getDefinition('tangible_ddd.subscriptions')
      ->replaceArgument(0, $specs)
      ->replaceArgument(1, ServiceLocatorTagPass::register($container, $listenerRefs));

    if ($container->hasDefinition('tangible_ddd.fact_class_resolver')) {
      $facts = $container->hasParameter('tangible_ddd.facts') ? (array) $container->getParameter('tangible_ddd.facts') : [];
      foreach ($specs as $spec) {
        if (class_exists($spec['event'])) {
          $facts[] = $spec['event'];
        }
      }
      $container->getDefinition('tangible_ddd.fact_class_resolver')->replaceArgument(1, array_values(array_unique($facts)));
    }
  }

  private static function eventOf(string $class, string $id): string {
    $instance = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    foreach (['event_class', 'get_event_class'] as $method) {
      if (method_exists($instance, $method)) {
        $value = (new \ReflectionMethod($instance, $method))->invoke($instance);
        if (is_string($value) && $value !== '') {
          return $value;
        }
      }
    }
    throw new InvalidArgumentException(
      "Integration listener \"$id\" ($class) declares no event: give #[AsIntegrationListener(event: ...)] or an event_class() returning a constant."
    );
  }

  private static function assertSubscribable(string $event, string $owner): void {
    if (interface_exists($event) || is_a($event, IIntegrationEvent::class, true)) {
      return;
    }
    throw new InvalidArgumentException("$owner subscribes to $event, which is neither an IIntegrationEvent nor a marker interface.");
  }
}
