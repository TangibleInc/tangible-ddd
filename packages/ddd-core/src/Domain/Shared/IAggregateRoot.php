<?php

declare(strict_types=1);

namespace TangibleDDD\Domain\Shared;

/**
 * An aggregate root, whatever its identity (TXP demand L2): it records
 * domain events and has a canonical name. Implemented by the 0.6 int-id
 * `Aggregate` and by the identity-agnostic `AggregateRoot`.
 *
 * canonical_name() is the local half of the root's at-rest identity (the
 * owning consumer's prefix supplies the other half: 'cred.license'); see
 * CanonicalName for the default and the rename trap.
 */
interface IAggregateRoot extends IRecordsDomainEvents {

  public static function canonical_name(): string;
}
