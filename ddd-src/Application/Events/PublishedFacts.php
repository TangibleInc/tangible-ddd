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

  /** @var \WeakMap<object, IIntegrationEvent> twin-lane source → announced record */
  private static ?\WeakMap $records = null;

  public static function mark(IIntegrationEvent $event, string $event_id): void {
    self::map()[$event] = $event_id;
  }

  /** The outbox event_id this instance was published as, or null. */
  public static function id_of(IIntegrationEvent $event): ?string {
    return self::map()[$event] ?? null;
  }

  /**
   * Associate a twin-lane SOURCE with its announced record: the source rides
   * the UoW log, the twin rides the bus — without this link the act's
   * finalise could never reach the twin's stamps or outbox identity.
   * EventRouter calls this at the one place twins are minted.
   */
  public static function link_source(object $source, IIntegrationEvent $fact): void {
    self::links()[$source] = $fact;
  }

  /** The announced record for a source (itself, for self-publishers), or null. */
  public static function fact_of(object $source): ?IIntegrationEvent {
    if ($source instanceof IIntegrationEvent) {
      return $source;
    }
    return self::links()[$source] ?? null;
  }

  private static function map(): \WeakMap {
    return self::$published ??= new \WeakMap();
  }

  private static function links(): \WeakMap {
    return self::$records ??= new \WeakMap();
  }
}
