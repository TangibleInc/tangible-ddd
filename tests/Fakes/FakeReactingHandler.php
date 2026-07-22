<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\EventHandlers\WordPressActionHandler;
use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * A framework-registered domain-event handler for FakeDomainEvent — the
 * reconstruction side of the reactions ledger: the closure hydrates its OWN
 * instance from the action args, never the published one.
 */
class FakeReactingHandler extends WordPressActionHandler {

  public static ?\Throwable $throw = null;

  /** @var IDomainEvent[] every reconstructed instance this handler saw */
  public static array $handled = [];

  protected function get_event_class(): string {
    return FakeDomainEvent::class;
  }

  public function handle(IDomainEvent $event): void {
    self::$handled[] = $event;

    if (self::$throw !== null) {
      throw self::$throw;
    }
  }
}
