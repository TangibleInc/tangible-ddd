<?php

namespace TangibleDDD\Domain\Exceptions;

/**
 * Thrown when a referenced entity cannot be found.
 *
 * Use this when an operation references an entity by ID
 * that doesn't exist in the repository.
 */
class RefNotFoundException extends \Exception {
}
