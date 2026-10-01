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
 * Error behaviour: for_fact() returns null when the event id is not a UUID
 * (hand-built or legacy payloads); the bracket then mints a random id as
 * before. Never throws.
 *
 * Lifetime: process-static, always restored by within()'s finally.
 */
final class DeterministicCommandId {

  private static ?string $next = null;

  /** The 32-hex uuid5(event_id, subscriber_id), or null when $eventId is not a UUID. */
  public static function for_fact(string $eventId, string $subscriberId): ?string {
    try {
      return str_replace('-', '', NameBasedUuid::v5($eventId, $subscriberId));
    } catch (\InvalidArgumentException) {
      return null;
    }
  }

  /**
   * Namespace of the process-step ids (fixed; changing it changes every
   * step command id).
   */
  public const PROCESS_NAMESPACE = '3f1d6c2e-8a4b-5e7f-9c0d-1b2a3c4d5e6f';

  /**
   * The 32-hex deterministic id of the $ordinal-th command a process step
   * dispatches (register 3.8 "inside a process step: uuid5(process_id,
   * step_index)", wave 3 CR-W3C-6): uuid5(uuid5(NS, "{consumer}:{process_id}"),
   * "{phase}:{step}:{ordinal}"), phase `step` for forward steps and `undo`
   * for compensations (keyed by the compensated step's name). A re-run of the
   * same step after a crash dispatches the same ids.
   */
  public static function for_step(string $consumer, int $processId, int|string $step, int $ordinal, bool $compensation = false): string {
    $process = NameBasedUuid::v5(self::PROCESS_NAMESPACE, $consumer . ':' . $processId);
    return str_replace('-', '', NameBasedUuid::v5($process, ($compensation ? 'undo' : 'step') . ':' . $step . ':' . $ordinal));
  }

  /**
   * Namespace of the work-item ids (fixed; changing it changes every item
   * command id).
   */
  public const WORKFLOW_NAMESPACE = '8d4a2f6b-1c3e-5a7d-9b0f-2e4c6a8d0b1f';

  /**
   * The 32-hex deterministic id of the $ordinal-th command a behaviour
   * workflow's work item dispatches (TXP demand W4, the sibling of
   * for_step): uuid5(uuid5(NS, "{consumer}:{workflow_id}"),
   * "item:{behaviour_idx}:{phase}:{ordinal}:{item_key}"). The item key is
   * last, so a key containing ':' cannot collide with another coordinate. A
   * re-run of the item (a crash after its command committed and before the
   * ledger saved it) dispatches the same id; a forked child workflow is a
   * new attempt and gets new ids.
   */
  public static function for_item(string $consumer, int $workflow_id, int $behaviour_idx, int $phase, string $item_key, int $ordinal = 0): string {
    $workflow = NameBasedUuid::v5(self::WORKFLOW_NAMESPACE, $consumer . ':' . $workflow_id);
    return str_replace('-', '', NameBasedUuid::v5($workflow, "item:$behaviour_idx:$phase:$ordinal:$item_key"));
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
