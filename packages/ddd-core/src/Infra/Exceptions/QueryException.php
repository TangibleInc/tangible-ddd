<?php

namespace TangibleDDD\Infra\Exceptions;

/**
 * Thrown when a database query fails.
 *
 * Use this for SQL errors, constraint violations, or other
 * database-level failures during repository operations.
 */
class QueryException extends \Exception {
}
