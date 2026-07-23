<?php

namespace TangibleDDD\Application\Events;

/**
 * "Which facts left this act?" — the facts roster, Reactions' sibling for
 * the PUBLISH side of a moment. Reactions records who fired inward; this
 * whiteboard records what crossed outward: every integration fact the bus
 * wrote from inside the current act, with its outbox event_id and the
 * domain moment that announced it (the twin edge).
 *
 * Array-based, not a WeakMap: rows are noted by VALUE because the roster is
 * drained wholesale into the act's audit JSON at finalise — nothing ever
 * looks a row up by instance. Per-act lifecycle: CorrelationMiddleware
 * wipes the board at bracket-open and drains it at finalise; acts never
 * nest (the bracket's guard), so one flat board suffices.
 *
 * The announcer stack mirrors Reactions' dispatch stack: EventRouter opens
 * a frame around the bus publish it performs for an announcing domain
 * event, so the bus can attribute announced_by = that moment's name().
 * A publish with no frame open is a momentless-port fact (direct bus use)
 * and gets announced_by null.
 */
final class ActFacts {

  /** @var list<array{name: string, event_id: string, announced_by: ?string}> */
  private static array $rows = [];

  /** @var list<string> currently-announcing domain event names, innermost last */
  private static array $announcers = [];

  /** Note one published fact on the current act's roster. */
  public static function note(string $name, string $event_id, ?string $announced_by): void {
    self::$rows[] = ['name' => $name, 'event_id' => $event_id, 'announced_by' => $announced_by];
  }

  /**
   * Hand over the roster and clear the board — finalise's verb.
   *
   * @return list<array{name: string, event_id: string, announced_by: ?string}>
   */
  public static function drain(): array {
    $rows = self::$rows;
    self::$rows = [];
    return $rows;
  }

  /** Bracket-open: this domain moment is now announcing its integration twin. */
  public static function announce_open(string $name): void {
    self::$announcers[] = $name;
  }

  /** Bracket-close: pop the innermost announcer frame. */
  public static function announce_close(): void {
    array_pop(self::$announcers);
  }

  /** The moment currently announcing, or null for a momentless-port publish. */
  public static function announcing(): ?string {
    return self::$announcers === [] ? null : self::$announcers[array_key_last(self::$announcers)];
  }

  /** Per-act wipe (bracket-open) and test hygiene. */
  public static function reset(): void {
    self::$rows = [];
    self::$announcers = [];
  }
}
