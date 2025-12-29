<?php

namespace TangibleDDD\Domain\Exceptions;

/**
 * Thrown when a business rule or constraint is violated.
 *
 * Use this for expected validation failures based on business rules,
 * e.g., "User cannot enroll in more than 5 programs simultaneously."
 */
class BusinessConstraintException extends \Exception {
}
