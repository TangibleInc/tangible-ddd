<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use Psr\Container\ContainerInterface;
use TangibleDDD\Application\BehaviourWorkflows\WorkflowIgniter;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Effects\IEffectCommand;
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
 *
 * Wave 5, with DeliveryNotes: a resume subscriber keeps the runner's
 * ResumeReport (LazyProcessEntry::resume_with_outcome()) and notes an
 * unheard fact (AW3); a listener's compensation notes which D1 failure
 * command it sent (E3). Ids, priorities and outcomes are unchanged.
 * has_subscribers() answers "does anything subscribe to this class" without building a
 * subscriber (the relay's SubscriptionProbe).
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
    private readonly ?DeliveryNotes $notes = null,
  ) {}

  /** True when a compiled spec or a boot-time subscriber takes $eventClass (by is_a); builds nothing. */
  public function has_subscribers(string $eventClass): bool {
    foreach ($this->specs as $spec) {
      if (is_a($eventClass, $spec['event'], true)) {
        return true;
      }
    }
    foreach ($this->added as $s) {
      if (is_a($eventClass, $s->event_class, true)) {
        return true;
      }
    }
    return false;
  }

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
    $listener = null;
    if ($spec['kind'] === 'listener') {
      $listener = $this->listeners->get($spec['service']);
      (new SubscriptionRegistrar($capture))->register_listener($listener);
    } elseif ($spec['kind'] === 'workflow') {
      if ($this->workflows === null) {
        throw new \LogicException("Workflow subscription {$spec['id']} needs a WorkflowIgniter (tangible_ddd.workflow_igniter); none is configured.");
      }
      $this->workflows->register($this->listeners->get($spec['service']), $capture, (string) $spec['prefix']);
    } else {
      (new SubscriptionRegistrar($capture, $this->processes))->register_process($spec['class']);
    }

    foreach ($capture->subscribers as $s) {
      $this->built[$s->id] ??= match (true) {
        $listener !== null && $s->on_exhausted !== null => $this->noting_compensation($s, $listener),
        $spec['kind'] === 'process' && str_starts_with($s->id, 'resume:') => $this->reporting_resume($s),
        default => $s,
      };
    }
    return $this->built[$spec['id']] ?? throw new \LogicException(sprintf(
      'Compiled subscription %s was not produced by SubscriptionRegistrar for %s (got: %s); the compile-time map and the registrar disagree.',
      $spec['id'], $spec['class'], implode(', ', array_map(fn (Subscriber $s) => $s->id, $capture->subscribers))
    ));
  }

  /** AW3: the resume door with the runner's report; an unheard fact is noted, never failed. */
  private function reporting_resume(Subscriber $s): Subscriber {
    $entry = $this->processes;
    if ($this->notes === null || !$entry instanceof LazyProcessEntry) {
      return $s;
    }
    $notes = $this->notes;
    return new Subscriber($s->id, $s->priority, $s->event_class,
      static function (IIntegrationEvent $event, string $eventId = '') use ($entry, $notes, $s): void {
        if ($entry->resume_with_outcome($event)?->is_unheard()) {
          $notes->unheard($s->id, $event, $eventId);
        }
      },
      $s->on_exhausted,
    );
  }

  /** E3: after the registrar's compensation returned, note the D1 failure command it sent. */
  private function noting_compensation(Subscriber $s, object $listener): Subscriber {
    if ($this->notes === null) {
      return $s;
    }
    $notes = $this->notes;
    $inner = $s->on_exhausted;
    return new Subscriber($s->id, $s->priority, $s->event_class, $s->handle,
      static function (IIntegrationEvent $event, \Throwable $last) use ($inner, $listener, $notes, $s): void {
        $inner($event, $last); // a throw keeps the compensation pending, as before
        $failure = self::failure_command_of($listener, $event, $last);
        if ($failure !== null) {
          $notes->compensated($s->id, Correlation::current_fact()?->event_id ?? '', $failure);
        }
      },
    );
  }

  /** The class of the failure command the registrar's compensation sends for $event; null when none. */
  private static function failure_command_of(object $listener, IIntegrationEvent $event, \Throwable $last): ?string {
    try {
      if (is_callable([$listener, 'translate'])) {
        $command = $listener->translate($event);
      } elseif (method_exists($listener, 'get_command')) {
        $command = (new \ReflectionMethod($listener, 'get_command'))->invoke($listener, $event);
      } else {
        return null;
      }
      // IEffectCommand, as core's SubscriptionRegistrar: an E1 handler-class
      // effect declares failure_command() too, not only IExternalEffectCommand.
      $failure = $command instanceof IEffectCommand ? $command->failure_command($last) : null;
      return $failure === null ? null : get_class($failure);
    } catch (\Throwable) {
      return null;
    }
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
