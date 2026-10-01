<?php

declare(strict_types=1);

namespace TangibleDDD\Domain\Exceptions;

/**
 * The state changed underneath the caller (TXP demand L10): an optimistic
 * version check lost, a unique key was taken, the invite was accepted
 * meanwhile. The core 409 family.
 *
 * A BusinessConstraintException subtype, so an edge that maps the parent to
 * 409 needs no change. Hosts re-parent their own persistence conflicts to it
 * (ddd-symfony PersistenceConflict), so an application tier can catch the
 * family without depending on a host package.
 */
class ConflictException extends BusinessConstraintException {
}
