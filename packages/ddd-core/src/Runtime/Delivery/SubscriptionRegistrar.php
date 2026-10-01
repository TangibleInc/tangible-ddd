<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

use Psr\Container\ContainerInterface;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\EventHandlers\IntegrationTranslator;
use TangibleDDD\Runtime\Ids\DeterministicCommandId;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Effects\IExternalEffectCommand;
use TangibleDDD\Runtime\Process\IProcessEntry;

/**
 * The one composition path from listener and process classes to
 * ISubscriptionRegistry, shared by wp, sf and pdo (ruling #80, register 3.5).
 *
 * registerListener(class|object):
 *   Accepts the IntegrationTranslator shape (public event_class() and
 *   translate(IIntegrationEvent): ?ICommand) and 0.6 IntegrationListener
 *   subclasses (read through their protected get_event_class()/get_command()).
 *   A class string is resolved through the container when it has it, else
 *   constructed without arguments; a 0.6 IntegrationListener subclass is
 *   built WITHOUT its self-registering constructor (see resolve()).
 *   Priority is Subscriber::LISTENER unless the class declares
 *   #[SubscriberPriority]. Id: `listener:<class>`. The translated command is
 *   sent with the deterministic id uuid5(event_id, subscriber_id)
 *   (DeterministicCommandId; register 3.8). On budget exhaustion, an
 *   IExternalEffectCommand's failureCommand() is sent.
 *
 * registerProcess(class-string<LongProcess>):
 *   Each #[StartsOn(E)] → `ignition:<process>@<E>` at Subscriber::IGNITION,
 *   calling IProcessEntry::ignite(). Each #[Awaits(E)] → `resume:<E>` at
 *   Subscriber::RESUME, calling IProcessEntry::resume() (one per fact class,
 *   however many processes await it: resume finds every waiter).
 *
 * Error behaviour: \InvalidArgumentException for anything that is not a
 * listener / LongProcess, an event class that is neither an
 * IIntegrationEvent nor an interface, or #[StartsOn] without a static
 * from_event(); \LogicException for registerProcess() without a process
 * entry.
 *
 * The register 3.5 sketch takes `ProcessRunner $runner`; CR-3 (ratified)
 * types it as the IProcessEntry port, which ProcessRunner implements from
 * wave 2 (so `new SubscriptionRegistrar($registry, $runner)` is the sketch's
 * call). Use one registration path per runner: the runner's own
 * register_event()/register_start() subscribe under consumer-prefixed ids,
 * so registering the same process through both would resume it twice.
 *
 * Lifetime: boot time; registering the same listener or process twice is
 * idempotent (the registry ignores duplicate ids).
 */
final class SubscriptionRegistrar {

  public function __construct(
    private readonly ISubscriptionRegistry $registry,
    private readonly ?IProcessEntry $processes = null,
    private readonly ?ContainerInterface $services = null,
  ) {}

  public function registerListener(string|object $listener): void {
    $instance = is_object($listener) ? $listener : $this->resolve($listener);
    [$eventClass, $translate] = $this->translatorOf($instance);
    $this->assertSubscribable($eventClass, get_class($instance));

    $priority = Subscriber::LISTENER;
    $attrs = (new \ReflectionClass($instance))->getAttributes(SubscriberPriority::class);
    if ($attrs !== []) {
      $priority = $attrs[0]->newInstance()->priority;
    }

    $id = 'listener:' . get_class($instance);
    $this->registry->add(new Subscriber(
      $id,
      $priority,
      $eventClass,
      static function (IIntegrationEvent $event, string $eventId = '') use ($translate, $id): void {
        $command = $translate($event);
        if ($command === null) {
          return;
        }
        // Register 3.8: inside a fact cause the command id is
        // uuid5(event_id, subscriber_id), so a redelivery repeats it.
        DeterministicCommandId::within(
          $eventId !== '' ? DeterministicCommandId::forFact($eventId, $id) : null,
          static fn () => $command->send()
        );
      },
      static function (IIntegrationEvent $event, \Throwable $last) use ($translate, $id): void {
        $command = $translate($event);
        if (!$command instanceof IExternalEffectCommand) {
          return;
        }
        $failure = $command->failureCommand($last);
        if ($failure === null) {
          return;
        }
        // D1: a re-fired compensation (crash before the ledger's terminal
        // marker) repeats the same command id, uuid5(event_id,
        // "{subscriber}#failure"), so the failure command can dedup on it.
        $eventId = Correlation::current_fact()?->eventId ?? '';
        DeterministicCommandId::within(
          $eventId !== '' ? DeterministicCommandId::forFact($eventId, $id . '#failure') : null,
          static fn () => $failure->send()
        );
      },
    ));
  }

  /** @param class-string<LongProcess> $processClass */
  public function registerProcess(string $processClass): void {
    if (!is_subclass_of($processClass, LongProcess::class)) {
      throw new \InvalidArgumentException("$processClass must extend " . LongProcess::class);
    }
    $entry = $this->processes ?? throw new \LogicException(
      "Cannot register $processClass: SubscriptionRegistrar was built without a process runner (IProcessEntry)."
    );

    $reflection = new \ReflectionClass($processClass);

    foreach ($reflection->getAttributes(StartsOn::class) as $attr) {
      $eventClass = $attr->newInstance()->event_class;
      $this->assertSubscribable($eventClass, $processClass);
      if (!method_exists($processClass, 'from_event')) {
        throw new \InvalidArgumentException(
          "$processClass declares #[StartsOn] but has no static from_event(); the ignition projection is required."
        );
      }
      $this->registry->add(new Subscriber(
        'ignition:' . $processClass . '@' . $eventClass,
        Subscriber::IGNITION,
        $eventClass,
        static function (IIntegrationEvent $event, string $eventId) use ($entry, $processClass): void {
          $entry->ignite($processClass, $event, $eventId);
        },
      ));
    }

    foreach ($reflection->getAttributes(Awaits::class) as $attr) {
      $eventClass = $attr->newInstance()->event_class;
      $this->assertSubscribable($eventClass, $processClass);
      $this->registry->add(new Subscriber(
        'resume:' . $eventClass,
        Subscriber::RESUME,
        $eventClass,
        static function (IIntegrationEvent $event) use ($entry): void {
          $entry->resume($event);
        },
      ));
    }
  }

  /**
   * Container first. Otherwise a translator whose constructor is INHERITED
   * from a framework base (the 0.6 IntegrationListener, whose constructor
   * self-registers on a WordPress hook) is built without running that
   * constructor: the registrar is the registration, and running it too would
   * subscribe twice on WordPress and fatal elsewhere (wave1-notes core
   * minor 3). Any other class is constructed without arguments.
   */
  private function resolve(string $class): object {
    if ($this->services?->has($class)) {
      return $this->services->get($class);
    }
    if (!class_exists($class)) {
      throw new \InvalidArgumentException("Listener class $class does not exist");
    }

    $reflection = new \ReflectionClass($class);
    $constructor = $reflection->getConstructor();
    if (
      $constructor !== null
      && $reflection->isSubclassOf(IntegrationTranslator::class)
      && $constructor->getDeclaringClass()->getName() !== $class
      && str_starts_with($constructor->getDeclaringClass()->getName(), 'TangibleDDD\\')
    ) {
      return $reflection->newInstanceWithoutConstructor();
    }

    return new $class();
  }

  /** @return array{0: string, 1: \Closure(IIntegrationEvent): ?ICommand} */
  private function translatorOf(object $listener): array {
    if (is_callable([$listener, 'event_class']) && is_callable([$listener, 'translate'])) {
      return [
        (string) $listener->event_class(),
        static fn (IIntegrationEvent $e): ?ICommand => $listener->translate($e),
      ];
    }

    // 0.6 IntegrationListener shape, matched by its protected hooks rather
    // than by class: that class moves to ddd-wp in wave 2, and core must not
    // name a wp FQCN. From wave 2 it extends IntegrationTranslator and takes
    // the branch above.
    if (method_exists($listener, 'get_event_class') && method_exists($listener, 'get_command')) {
      $event_class = new \ReflectionMethod($listener, 'get_event_class');
      $get_command = new \ReflectionMethod($listener, 'get_command');
      return [
        (string) $event_class->invoke($listener),
        static fn (IIntegrationEvent $e): ?ICommand => $get_command->invoke($listener, $e),
      ];
    }

    throw new \InvalidArgumentException(sprintf(
      '%s is not a listener: expected event_class() + translate(), or get_event_class() + get_command()',
      get_class($listener)
    ));
  }

  private function assertSubscribable(string $eventClass, string $owner): void {
    if (interface_exists($eventClass) || is_a($eventClass, IIntegrationEvent::class, true)) {
      return;
    }
    throw new \InvalidArgumentException("$owner subscribes to $eventClass, which is neither an IIntegrationEvent nor a marker interface");
  }
}
