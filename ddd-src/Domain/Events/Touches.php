<?php

namespace TangibleDDD\Domain\Events;

use TangibleDDD\Domain\Shared\Aggregate;

/**
 * Marks a fact as declaring a state write — the DECLARED write-set
 * (spec appendix 9; owner naming ruling 2026-07-19: Touches).
 *
 *   #[Touches(Op::Created, License::class, id: 'license_id')]
 *
 * The aggregate is referenced by CLASS (IDE autocomplete, rename-refactoring,
 * PHPStan via class-string) — the canonical STRING (e.g. 'cred.license') is
 * at-rest dialect only, resolved at harvest via owner_of() +
 * Aggregate::canonical_name(). ⚠️ Rename trap: refactors follow this class
 * ref, silently changing the derived at-rest name — on first rename, the
 * aggregate must pin canonical_name() to the historical string.
 *
 * `id:` names the ctor param carrying the subject id; when omitted the
 * convention `{canonical_name}_id` applies. Validated by the conformance
 * scan (the hard gate); the harvest itself never throws.
 *
 * Repeatable: a fact may touch several aggregates.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final class Touches {

  /** @param class-string<Aggregate> $aggregate */
  public function __construct(
    public readonly Op $op,
    public readonly string $aggregate,
    public readonly ?string $id = null,
  ) {
    if (!is_subclass_of($this->aggregate, Aggregate::class)) {
      throw new TouchesNonAggregate($this->aggregate);
    }
  }
}
