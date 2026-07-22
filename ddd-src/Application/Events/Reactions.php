<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Events\IDomainEvent;

/**
 * "Who reacted to this moment?" — the reactions ledger's whiteboard,
 * PublishedFacts' sibling for the handler side of a publication.
 *
 * The dispatcher opens a frame around each do_action; the framework's
 * action handlers record themselves (class + duration, error on throw)
 * into whatever frame is CURRENTLY open. Attribution is positional — a
 * stack, never the handler-side instance — because WordPressActionHandler
 * reconstructs its event from the action args: the object a handler holds
 * is NOT the object the dispatcher published. Identity is per-INSTANCE
 * (a WeakMap — entries die with their objects, no worker leakage), so the
 * finalise-time lookup on published() instances hits, and the rows
 * evaporate when the unit of work drops its refs.
 */
final class Reactions {

  /** @var \WeakMap<IDomainEvent, list<array{handler: class-string, duration_ms: int, error?: string}>> */
  private static ?\WeakMap $by_event = null;

  /** @var list<IDomainEvent> currently-dispatching events, innermost last */
  private static array $stack = [];

  /** Bracket-open: this event is now being dispatched. */
  public static function open(IDomainEvent $event): void {
    self::$stack[] = $event;
  }

  /** Bracket-close: pop the innermost dispatch frame. */
  public static function close(): void {
    array_pop(self::$stack);
  }

  /**
   * Attribute one handler run to the event currently dispatching.
   * Outside any frame this is a silent no-op — a bare do_action replay
   * has no moment to write against.
   */
  public static function record(string $handler, int $duration_ms, ?\Throwable $error = null): void {
    if (self::$stack === []) {
      return;
    }
    $event = self::$stack[array_key_last(self::$stack)];

    $row = ['handler' => $handler, 'duration_ms' => $duration_ms];
    if ($error !== null) {
      $row['error'] = $error->getMessage();
    }

    $rows = self::map()[$event] ?? [];
    $rows[] = $row;
    self::map()[$event] = $rows;
  }

  /**
   * The reactions recorded against this instance, or [].
   *
   * @return list<array{handler: class-string, duration_ms: int, error?: string}>
   */
  public static function of(IDomainEvent $event): array {
    return self::map()[$event] ?? [];
  }

  /** Test hygiene only — production cleanup is the WeakMap's job. */
  public static function reset(): void {
    self::$by_event = null;
    self::$stack = [];
  }

  private static function map(): \WeakMap {
    return self::$by_event ??= new \WeakMap();
  }
}
