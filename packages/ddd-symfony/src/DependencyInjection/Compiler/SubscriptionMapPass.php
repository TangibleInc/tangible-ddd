<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use TangibleDDD\Application\BehaviourWorkflows\IStartsFromFact;
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
 * - Behaviour workflows tagged `tangible_ddd.workflow` (IStartsFromFact,
 *   autoconfigured; D10): each #[StartsOn(E)] is a core WorkflowIgniter
 *   subscriber (IGNITION), id `{prefix}/workflow-ignition:{class}@{fact}`,
 *   deduped by the workflow ignition ledger. Like listeners, the workflow
 *   service is built at the first matching delivery.
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

    // Wave 5: one map per consumer; a subscription belongs to the consumer whose
    // namespace root contains its listener, process or workflow class (longest
    // root; the primary consumer for a class outside every root).
    $consumers = $container->hasParameter('tangible_ddd.consumers')
      ? array_values((array) $container->getParameter('tangible_ddd.consumers'))
      : [['name' => '', 'primary' => true, 'prefix' => (string) (((array) ($container->hasParameter('tangible_ddd.consumer') ? $container->getParameter('tangible_ddd.consumer') : []))['prefix'] ?? ''), 'namespace_root' => '']];
    /** @var array<int, array{specs: list<array<string, mixed>>, refs: array<string, Reference>, seen: array<string, true>}> $groups */
    $groups = array_fill_keys(array_keys($consumers), ['specs' => [], 'refs' => [], 'seen' => []]);
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

      $g = self::owner($class, $consumers);
      $groups[$g]['specs'][] = CompiledSubscriptionRegistry::listener_spec($id, $class, $event, $priority);
      $groups[$g]['refs'][$id] = new Reference($id);
    }

    foreach ($container->findTaggedServiceIds(DddTags::LONG_PROCESS) as $id => $tags) {
      $class = $bag->resolveValue($container->getDefinition($id)->getClass() ?? $id);
      if (!is_string($class) || !is_subclass_of($class, LongProcess::class)) {
        continue; // LongProcessCatalogPass reports misuse of the tag
      }
      $reflection = new \ReflectionClass($class);
      $g = self::owner($class, $consumers);
      foreach ($reflection->getAttributes(StartsOn::class) as $a) {
        $event = $a->newInstance()->event_class;
        self::assertSubscribable($event, $class);
        $groups[$g]['specs'][] = CompiledSubscriptionRegistry::process_spec($class, 'ignition', $event);
      }
      foreach ($reflection->getAttributes(Awaits::class) as $a) {
        $event = $a->newInstance()->event_class;
        self::assertSubscribable($event, $class);
        $spec = CompiledSubscriptionRegistry::process_spec($class, 'resume', $event);
        if (!isset($groups[$g]['seen'][$spec['id']])) {
          $groups[$g]['seen'][$spec['id']] = true;
          $groups[$g]['specs'][] = $spec;
        }
      }
    }

    // D10: behaviour workflows ignited by facts (core WorkflowIgniter).
    foreach ($container->findTaggedServiceIds(DddTags::WORKFLOW) as $id => $tags) {
      $class = $bag->resolveValue($container->getDefinition($id)->getClass() ?? $id);
      if (!is_string($class) || !is_a($class, IStartsFromFact::class, true)) {
        throw new InvalidArgumentException("Workflow service \"$id\" is tagged " . DddTags::WORKFLOW . ' but does not implement ' . IStartsFromFact::class . '.');
      }
      $facts = array_values(array_unique(array_map(
        static fn (\ReflectionAttribute $a) => $a->newInstance()->event_class,
        (new \ReflectionClass($class))->getAttributes(StartsOn::class),
      )));
      if ($facts === []) {
        throw new InvalidArgumentException("$class implements IStartsFromFact but declares no #[StartsOn(SomeFact::class)].");
      }
      $g = self::owner($class, $consumers);
      foreach ($facts as $event) {
        if (!is_a($event, IIntegrationEvent::class, true)) {
          throw new InvalidArgumentException("$class #[StartsOn($event)]: $event must implement IIntegrationEvent.");
        }
        $groups[$g]['specs'][] = CompiledSubscriptionRegistry::workflow_spec($id, $class, $event, (string) $consumers[$g]['prefix']);
      }
      $groups[$g]['refs'][$id] = new Reference($id);
    }

    $extra = $container->hasParameter('tangible_ddd.facts') ? (array) $container->getParameter('tangible_ddd.facts') : [];
    foreach ($consumers as $g => $c) {
      $subscriptions = self::id($c, 'subscriptions');
      if (!$container->hasDefinition($subscriptions)) {
        continue;
      }
      $specs = $groups[$g]['specs'];
      $container->getDefinition($subscriptions)
        ->replaceArgument(0, $specs)
        ->replaceArgument(1, ServiceLocatorTagPass::register($container, $groups[$g]['refs']));

      $resolver = self::id($c, 'fact_class_resolver');
      if ($container->hasDefinition($resolver)) {
        $facts = $extra;
        foreach ($specs as $spec) {
          if (class_exists($spec['event'])) {
            $facts[] = $spec['event'];
          }
        }
        $container->getDefinition($resolver)->replaceArgument(1, array_values(array_unique($facts)));
      }
    }
  }

  /** @param array<string, mixed> $consumer */
  private static function id(array $consumer, string $service): string {
    return ($consumer['primary'] ?? true) ? 'tangible_ddd.' . $service : sprintf('tangible_ddd.consumer.%s.%s', $consumer['name'], $service);
  }

  /**
   * The index of the consumer that owns $class: the longest namespace root
   * containing it (whole segments), else the primary consumer (0).
   *
   * @param list<array<string, mixed>> $consumers
   */
  public static function owner(string $class, array $consumers): int {
    $best = 0;
    $length = -1;
    $class = ltrim($class, '\\');
    foreach ($consumers as $i => $c) {
      $root = trim((string) ($c['namespace_root'] ?? ''), '\\');
      if ($root !== '' && ($class === $root || str_starts_with($class, $root . '\\')) && strlen($root) > $length) {
        $best = $i;
        $length = strlen($root);
      }
    }
    return $best;
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
