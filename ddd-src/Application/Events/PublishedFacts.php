<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * "Has this instance already crossed?" (0.3 lane 5) — the re-raise guard's
 * memory, replacing the mutable journey slots that used to live ON facts.
 *
 * The bus marks an instance at publication; EventsUnitOfWork refuses to
 * re-record a marked instance (AlreadyIntegrated). Identity is per-INSTANCE
 * (a WeakMap — entries die with their objects, no worker leakage): a
 * hydrated twin on the drain side is a different object and records fine —
 * its at-rest identity lives on the outbox row and travels on the envelope,
 * never on the fact itself. Facts are immutable records now.
 */
final class PublishedFacts {

  /** @var \WeakMap<IIntegrationEvent, string> instance → outbox event_id */
  private static ?\WeakMap $published = null;

  public static function mark(IIntegrationEvent $event, string $event_id): void {
    self::map()[$event] = $event_id;
  }

  /** The outbox event_id this instance was published as, or null. */
  public static function id_of(IIntegrationEvent $event): ?string {
    return self::map()[$event] ?? null;
  }

  private static function map(): \WeakMap {
    return self::$published ??= new \WeakMap();
  }
}
