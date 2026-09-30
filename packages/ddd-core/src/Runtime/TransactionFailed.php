<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/** BEGIN, COMMIT or ROLLBACK failed; previous = the driver error. */
final class TransactionFailed extends \RuntimeException {}
