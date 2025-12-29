<?php

namespace TangibleDDD\Infra\Exceptions;

/**
 * Thrown when code is used incorrectly by the developer.
 *
 * This indicates a programming error, not a runtime condition.
 * Examples: missing configuration, incorrect method call order, invalid arguments.
 */
class IncorrectUsageException extends \Exception {
}
