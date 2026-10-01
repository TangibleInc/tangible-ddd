<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/**
 * Journal of performed external effects, keyed by idempotency_key() (D1).
 *
 * - find(): the recorded result, or null when the effect has not performed.
 * - store(): records a result; storing an existing key overwrites it.
 * - invalidate(): the explicit repair path. It runs inside the repair
 *   command's transaction BEFORE that command re-dispatches, so the effect
 *   performs again; re-dispatching under a new command id alone does not
 *   bypass the journal. Invalidating an unknown key is a no-op.
 *
 * Error behaviour: storage failures throw \RuntimeException.
 * Connection rules: the domain connection (so invalidate commits with the
 * repair command).
 */
interface IEffectJournal {

  public function find(string $key): ?EffectResult;

  public function store(string $key, EffectResult $r): void;

  public function invalidate(string $key, string $reason): void;
}
