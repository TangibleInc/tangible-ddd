<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Process;

/**
 * A version-fenced save or touch matched 0 rows: someone else (a holder
 * whose session lock silently vanished, section 5.2) advanced the process.
 * The wake aborts and is retried; nothing was overwritten.
 */
final class ConcurrentProcessModification extends \RuntimeException {}
