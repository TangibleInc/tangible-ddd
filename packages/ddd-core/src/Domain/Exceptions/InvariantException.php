<?php

namespace TangibleDDD\Domain\Exceptions;

/**
 * Thrown when a domain invariant is violated.
 *
 * Use this for violations of fundamental domain rules that should never occur
 * if the system is functioning correctly.
 */
class InvariantException extends \Exception {
}
