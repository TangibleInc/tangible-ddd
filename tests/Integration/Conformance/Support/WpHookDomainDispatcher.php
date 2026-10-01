<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance\Support;

use TangibleDDD\Application\Events\IDomainEventDispatcher;
use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Infra\Services\WordPressEventDispatcher;

/**
 * The bus's domain-event dispatcher on the WordPress host: the REAL 0.6
 * WordPressEventDispatcher (Reactions frame, do_action_ref_array on the
 * event's `{prefix}_domain_*` action, a throwing callback propagating out
 * of do_action), plus listen() for the scenarios' in-transaction reactions.
 *
 * WordPress callbacks receive the payload, not the instance; the scenarios
 * need the PUBLISHED instance (the re-raise guard keys on it), so listen()
 * binds a zero-argument add_action callback that hands its listener the
 * event this dispatcher is currently dispatching. Ordering is WordPress'
 * own (priority, then registration order).
 */
final class WpHookDomainDispatcher implements IDomainEventDispatcher {

  /** @var list<IDomainEvent> */
  private array $dispatching = [];

  /** @var list<array{0: string, 1: \Closure, 2: int}> */
  private array $bound = [];

  public function __construct(private readonly WordPressEventDispatcher $inner = new WordPressEventDispatcher()) {}

  /** @param class-string<IDomainEvent> $eventClass */
  public function listen(string $eventClass, callable $listener, int $priority = 10): void {
    if (!is_a($eventClass, IDomainEvent::class, true) || !method_exists($eventClass, 'action')) {
      throw new \LogicException("$eventClass has no WordPress domain action; the wp host binds concrete domain event classes only");
    }
    $hook = $eventClass::action();
    $callback = function () use ($listener): void {
      $listener($this->dispatching[array_key_last($this->dispatching)]);
    };
    add_action($hook, $callback, $priority, 0);
    $this->bound[] = [$hook, $callback, $priority];
  }

  public function dispatch(IDomainEvent $event): void {
    $this->dispatching[] = $event;
    try {
      $this->inner->dispatch($event);
    } finally {
      array_pop($this->dispatching);
    }
  }

  /** Remove every callback listen() bound. */
  public function unbindAll(): void {
    foreach ($this->bound as [$hook, $callback, $priority]) {
      remove_action($hook, $callback, $priority);
    }
    $this->bound = [];
  }
}
