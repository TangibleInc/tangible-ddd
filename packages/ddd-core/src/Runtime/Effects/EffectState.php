<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/**
 * Where a journal entry is (TXP demand E2, wave 5). Values are persisted.
 *
 * - Performed: perform() returned and its result was stored, record() has
 *   not committed (it failed, its budget is spent, or the worker died). The
 *   provider charged, purged or created; the domain does not know yet.
 * - Recorded: record() committed with the entry's result.
 */
enum EffectState: string {
  case Performed = 'performed';
  case Recorded = 'recorded';
}
