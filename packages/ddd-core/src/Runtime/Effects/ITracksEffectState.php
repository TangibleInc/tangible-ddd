<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/**
 * An IEffectJournal that keeps each entry's state (TXP demand E2, wave 5):
 * performed, then recorded. A separate interface, so a wave-4 journal keeps
 * implementing IEffectJournal unchanged; EffectMiddleware uses the states
 * only when its journal implements this.
 *
 * - store(): writes the entry Performed (performed_at = now, recorded_at
 *   null), in its own autocommit right after perform(), as before.
 * - mark_recorded(): sets Recorded and recorded_at = now. RecordEffect calls
 *   it inside record()'s transaction, after record() returned, so the mark
 *   commits or rolls back with record(). An unknown key is a no-op.
 * - find_entry(): the entry with its state, or null. find() keeps returning
 *   only the result, whatever the state.
 * - find_unrecorded(): Performed entries whose performed_at is before
 *   $performed_before, oldest first, at most $limit ("charged but not
 *   written down"; UnrecordedEffects shows them to the operator).
 * - invalidate(): removes the entry in either state.
 *
 * The retry rule EffectMiddleware applies: no entry → perform, store, record;
 * Performed → reuse the result, run record() again (the domain write is
 * still owed); Recorded → reuse the result and return it, record() does not
 * run again (effectively once); only invalidate() performs again.
 *
 * Error behaviour: storage failures throw \RuntimeException.
 * Connection rules: the domain connection (mark_recorded and invalidate
 * commit with the command's transaction).
 */
interface ITracksEffectState extends IEffectJournal {

  public function mark_recorded(string $key): void;

  public function find_entry(string $key): ?EffectEntry;

  /** @return list<EffectEntry> */
  public function find_unrecorded(\DateTimeImmutable $performed_before, int $limit): array;
}
