<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Container\ContainerInterface;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgniter;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistrar;
use TangibleDDD\Runtime\Process\IProcessEntry;

/**
 * ISubscriptionRegistry built from a COMPILE-TIME subscription map (D2, E S4,
 * S5): the bundle's SubscriptionMapPass discovers listeners (autoconfigured
 * services) and processes (`ddd.long_process`, #[StartsOn] / #[Awaits]) and
 * hands this class plain specs. This replaces WordPress's constructor side
 * effects (`integration_listener()` in the listener constructor) and the
 * eager namespace scan: no listener is constructed at boot.
 *
 * for($factClass) matches specs by is_a (so marker interfaces work), orders
 * them by priority, then compile order, then builds each Subscriber through
 * the CORE SubscriptionRegistrar (listener translation, D1 failure command,
 * ignition / resume doors), lazily and once per spec. The subscriber ids and
 * priorities are exactly the registrar's (`listener:<class>`,
 * `ignition:<process>@<fact>`, `resume:<fact>`, #[SubscriberPriority]), so
 * the delivery ledger keys agree with every other host.
 *
 * Workflow specs (D10) are built through the core WorkflowIgniter::register()
 * with the workflow service from the same locator, so their ids are the
 * igniter's (`{prefix}/workflow-ignition:<class>@<fact>`).
 *
 * A process subscription without an IProcessEntry throws the registrar's
 * LogicException at delivery: loud, never a silently dropped ignition; a
 * workflow subscription without a WorkflowIgniter does the same.
 * add() keeps boot-time additions after the compiled subscribers; duplicate
 * ids are ignored (first wins), like the core registry.
 */
final class CompiledSubscriptionRegistry implements ISubscriptionRegistry {

  /** @var array<string, Subscriber> spec id → built subscriber */
  private array $built = [];

  /** @var array<string, Subscriber> */
  private array $added = [];

  /**
   * @param list<array{id: string, kind: string, event: string, priority: int, service?: string, class: string, role?: string}> $specs
   * @param ContainerInterface $listeners listener services by service id (a ServiceLocator)
   */
  public function __construct(
    private readonly array $specs,
    private readonly ContainerInterface $listeners,
    private readonly ?IProcessEntry $processes = null,
    private readonly ?WorkflowIgniter $workflows = null,
  ) {}

  /**
   * D10: a behaviour workflow ignited by $eventClass through the core
   * WorkflowIgniter. The id is the igniter's own subscriber id.
   *
   * @return array{id: string, kind: string, event: string, priority: int, service: string, class: string, prefix: string}
   */
  public static function workflow_spec(string $serviceId, string $class, string $eventClass, string $consumerPrefix): array {
    return [
      'id' => $consumerPrefix . '/workflow-ignition:' . $class . '@' . $eventClass,
      'kind' => 'workflow', 'event' => $eventClass, 'priority' => Subscriber::IGNITION,
      'service' => $serviceId, 'class' => $class, 'prefix' => $consumerPrefix,
    ];
  }

  /** @return array{id: string, kind: string, event: string, priority: int, service: string, class: string} */
  public static function listener_spec(string $serviceId, string $class, string $eventClassOrMarker, int $priority): array {
    return ['id' => 'listener:' . $class, 'kind' => 'listener', 'event' => $eventClassOrMarker, 'priority' => $priority, 'service' => $serviceId, 'class' => $class];
  }

  /**
   * @param 'ignition'|'resume' $role
   * @return array{id: string, kind: string, event: string, priority: int, class: string, role: string}
   */
  public static function process_spec(string $processClass, string $role, string $eventClass): array {
    return $role === 'ignition'
      ? ['id' => 'ignition:' . $processClass . '@' . $eventClass, 'kind' => 'process', 'event' => $eventClass, 'priority' => Subscriber::IGNITION, 'class' => $processClass, 'role' => $role]
      : ['id' => 'resume:' . $eventClass, 'kind' => 'process', 'event' => $eventClass, 'priority' => Subscriber::RESUME, 'class' => $processClass, 'role' => $role];
  }

  public function add(Subscriber $s): void {
    if (isset($this->added[$s->id]) || $this->hasSpec($s->id)) {
      return;
    }
    $this->added[$s->id] = $s;
  }

  public function for(string $eventClass): array {
    $matching = [];
    foreach ($this->specs as $i => $spec) {
      if (is_a($eventClass, $spec['event'], true)) {
        $matching[] = ['p' => $spec['priority'], 'i' => $i, 'spec' => $spec];
      }
    }
    $offset = count($this->specs);
    foreach (array_values($this->added) as $j => $s) {
      if (is_a($eventClass, $s->event_class, true)) {
        $matching[] = ['p' => $s->priority, 'i' => $offset + $j, 'sub' => $s];
      }
    }
    usort($matching, static fn (array $a, array $b) => [$a['p'], $a['i']] <=> [$b['p'], $b['i']]);

    return array_map(fn (array $m) => $m['sub'] ?? $this->build($m['spec']), $matching);
  }

  /** @return list<array<string, mixed>> the compiled map (debugging, conformance) */
  public function specs(): array {
    return $this->specs;
  }

  /** @return list<class-string> concrete (non-interface) fact classes the map names */
  public function fact_classes(): array {
    $classes = [];
    foreach ($this->specs as $spec) {
      if (class_exists($spec['event'])) {
        $classes[$spec['event']] = true;
      }
    }
    return array_keys($classes);
  }

  /** @param array<string, mixed> $spec */
  private function build(array $spec): Subscriber {
    if (isset($this->built[$spec['id']])) {
      return $this->built[$spec['id']];
    }

    $capture = new CapturingRegistry();
    if ($spec['kind'] === 'listener') {
      (new SubscriptionRegistrar($capture))->register_listener($this->listeners->get($spec['service']));
    } elseif ($spec['kind'] === 'workflow') {
      if ($this->workflows === null) {
        throw new \LogicException("Workflow subscription {$spec['id']} needs a WorkflowIgniter (tangible_ddd.workflow_igniter); none is configured.");
      }
      $this->workflows->register($this->listeners->get($spec['service']), $capture, (string) $spec['prefix']);
    } else {
      (new SubscriptionRegistrar($capture, $this->processes))->register_process($spec['class']);
    }

    foreach ($capture->subscribers as $s) {
      $this->built[$s->id] ??= $s;
    }
    return $this->built[$spec['id']] ?? throw new \LogicException(sprintf(
      'Compiled subscription %s was not produced by SubscriptionRegistrar for %s (got: %s); the compile-time map and the registrar disagree.',
      $spec['id'], $spec['class'], implode(', ', array_map(fn (Subscriber $s) => $s->id, $capture->subscribers))
    ));
  }

  private function hasSpec(string $id): bool {
    foreach ($this->specs as $spec) {
      if ($spec['id'] === $id) {
        return true;
      }
    }
    return false;
  }
}
