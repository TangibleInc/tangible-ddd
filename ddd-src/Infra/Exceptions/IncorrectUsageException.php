<?php

namespace TangibleDDD\Infra\Exceptions;

use TangibleDDD\Domain\Exceptions\IncorrectUsage;

/**
 * @deprecated 0.6.12 Throw and catch \TangibleDDD\Domain\Exceptions\IncorrectUsage.
 * Kept so an existing catch (IncorrectUsageException) still compiles and still matches.
 */
class IncorrectUsageException extends IncorrectUsage {
}
