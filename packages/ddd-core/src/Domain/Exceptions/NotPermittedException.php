<?php

declare(strict_types=1);

namespace TangibleDDD\Domain\Exceptions;

/**
 * The actor may not do this (TXP demand L7): an authorization refusal
 * decided by the domain, e.g. "only an owner may remove a member".
 *
 * A BusinessConstraintException subtype, so existing catches of the parent
 * still match. Edges can map it apart from conflicts: an HTTP edge answers
 * 403 for this and 409 for the other BusinessConstraintExceptions.
 */
class NotPermittedException extends BusinessConstraintException {
}
