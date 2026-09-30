<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

use Psr\Container\ContainerInterface;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\ProcessRunner;
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
 *   constructed without arguments. Priority is Subscriber::LISTENER unless
 *   the class declares #[SubscriberPriority]. Id: `listener:<class>`.
 *   On budget exhaustion, an IExternalEffectCommand's failureCommand() is sent.
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
 * entry, and at construction for a ProcessRunner that does not implement
 * IProcessEntry yet (the 0.6 runner keeps its ignition dedup inside its own
 * hook closure, so wrapping it here would lose bug-2 protection).
 *
 * Lifetime: boot time; registering the same listener or process twice is
 * idempotent (the registry ignores duplicate ids).
 */
final class SubscriptionRegistrar {

  private readonly ?IProcessEntry $processes;

  public function __construct(
    private readonly ISubscriptionRegistry $registry,
    ProcessRunner|IProcessEntry|null $runner = null,
    private readonly ?ContainerInterface $services = null,
  ) {
    if ($runner !== null && !$runner instanceof IProcessEntry) {
      throw new \LogicException(
        'This ProcessRunner does not implement ' . IProcessEntry::class
        . ' yet (wave 2/3); pass an IProcessEntry to SubscriptionRegistrar.'
      );
    }
    $this->processes = $runner;
  }

  public function registerListener(string|object $listener): void {
    $instance = is_object($listener) ? $listener : $this->resolve($listener);
    [$eventClass, $translate] = $this->translatorOf($instance);
    $this->assertSubscribable($eventClass, get_class($instance));

    $priority = Subscriber::LISTENER;
    $attrs = (new \ReflectionClass($instance))->getAttributes(SubscriberPriority::class);
    if ($attrs !== []) {
      $priority = $attrs[0]->newInstance()->priority;
    }

    $this->registry->add(new Subscriber(
      'listener:' . get_class($instance),
      $priority,
      $eventClass,
      static function (IIntegrationEvent $event) use ($translate): void {
        $translate($event)?->send();
      },
      static function (IIntegrationEvent $event, \Throwable $last) use ($translate): void {
        $command = $translate($event);
        if ($command instanceof IExternalEffectCommand) {
          $command->failureCommand($last)?->send();
        }
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

  private function resolve(string $class): object {
    if ($this->services?->has($class)) {
      return $this->services->get($class);
    }
    if (!class_exists($class)) {
      throw new \InvalidArgumentException("Listener class $class does not exist");
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
