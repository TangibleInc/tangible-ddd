<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process\Repair;

/**
 * A stranded-process repair was refused by one of its guards: the process is
 * unknown, not in the stranded scan (it has a live intent, is too recent, or
 * is suspended / finished), its lock is held by a worker, or it moved past
 * the version the operator expected. Nothing was written.
 */
final class ProcessNotStranded extends \RuntimeException {}
