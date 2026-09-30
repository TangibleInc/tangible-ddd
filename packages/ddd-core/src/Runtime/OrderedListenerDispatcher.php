<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * Local, synchronous domain-event dispatcher for non-WordPress hosts
 * (register 3.5; the portable WordPressEventDispatcher).
 *
 * Listeners subscribe to a class or a marker interface (D2, matched with
 * instanceof) and run by priority ascending, then registration order. Each
 * listener receives the PUBLISHED instance.
 *
 * Opens and closes the Reactions frame around the dispatch like
 * WordPressEventDispatcher (try/finally) and records each listener run
 * (name + duration, error on throw) against the published instance.
 *
 * Error behaviour: the FIRST listener exception propagates (after it is
 * recorded), later listeners do not run, and the command rolls back.
 *
 * Lifetime: listeners are registered at boot; the dispatcher holds no
 * per-message state.
 */
final class OrderedListenerDispatcher implements IDomainEventDispatcher {

  /** @var list<array{class: string, listener: callable, priority: int, seq: int, name: string}> */
  private array $listeners = [];

  private int $seq = 0;

  /**
   * @param class-string $eventClassOrMarker
   * @param callable(IDomainEvent): mixed $listener
   * @param string|null $name the handler name recorded in Reactions (defaults to the callable's name)
   */
  public function listen(string $eventClassOrMarker, callable $listener, int $priority = 10, ?string $name = null): void {
    $this->listeners[] = [
      'class' => $eventClassOrMarker,
      'listener' => $listener,
      'priority' => $priority,
      'seq' => ++$this->seq,
      'name' => $name ?? self::nameOf($listener),
    ];
  }

  public function dispatch(IDomainEvent $event): void {
    $matching = array_values(array_filter($this->listeners, static fn (array $l) => $event instanceof $l['class']));
    usort($matching, static fn (array $a, array $b) => [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']]);

    Reactions::open($event);
    try {
      foreach ($matching as $l) {
        $start = hrtime(true);
        try {
          ($l['listener'])($event);
        } catch (\Throwable $e) {
          Reactions::record($l['name'], self::ms($start), $e);
          throw $e;
        }
        Reactions::record($l['name'], self::ms($start));
      }
    } finally {
      Reactions::close();
    }
  }

  private static function ms(int|float $start): int {
    return (int) round((hrtime(true) - $start) / 1e6);
  }

  private static function nameOf(callable $listener): string {
    if ($listener instanceof \Closure) {
      return 'Closure';
    }
    if (is_object($listener)) {
      return get_class($listener);
    }
    if (is_array($listener)) {
      $target = is_object($listener[0]) ? get_class($listener[0]) : (string) $listener[0];
      return $target . '::' . $listener[1];
    }
    return (string) $listener;
  }
}
