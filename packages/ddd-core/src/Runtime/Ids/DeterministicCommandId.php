<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Ids;

/**
 * Deterministic command ids inside a fact cause (register 3.8, wave1-notes
 * core minor 2): a listener's translated command gets
 * `uuid5(event_id, subscriber_id)`, so a redelivery of the same fact to the
 * same subscriber dispatches a command with the same id.
 *
 * Spelling: the 32-hex form of that uuid5 (dashes removed), the same shape
 * as the random ids CorrelationMiddleware mints (`command_audit.command_id`
 * is CHAR(32) on wp). It is the same value as the hyphenated uuid5.
 *
 * Mechanism: the subscriber runs the send inside within($id, ...); the act
 * bracket (CorrelationMiddleware) calls take() when it mints the command id.
 * take() consumes the hint, so only the first command dispatched in the
 * window gets it; nested windows restore the outer hint on exit.
 *
 * Error behaviour: forFact() returns null when the event id is not a UUID
 * (hand-built or legacy payloads); the bracket then mints a random id as
 * before. Never throws.
 *
 * Lifetime: process-static, always restored by within()'s finally.
 */
final class DeterministicCommandId {

  private static ?string $next = null;

  /** The 32-hex uuid5(event_id, subscriber_id), or null when $eventId is not a UUID. */
  public static function forFact(string $eventId, string $subscriberId): ?string {
    try {
      return str_replace('-', '', NameBasedUuid::v5($eventId, $subscriberId));
    } catch (\InvalidArgumentException) {
      return null;
    }
  }

  /**
   * Run $work with $id as the next command id; null runs it without a hint.
   *
   * @template T
   * @param callable():T $work
   * @return T
   */
  public static function within(?string $id, callable $work): mixed {
    $outer = self::$next;
    self::$next = $id;
    try {
      return $work();
    } finally {
      self::$next = $outer;
    }
  }

  /** Consume the pending hint (the act bracket calls this once per command). */
  public static function take(): ?string {
    $id = self::$next;
    self::$next = null;
    return $id;
  }

  /** Read the pending hint without consuming it (diagnostics, tests). */
  public static function peek(): ?string {
    return self::$next;
  }
}
