<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

/**
 * State survived a message boundary: an open correlation scope, a held
 * process lock, or a resetter that failed. A bracket bug, never retryable.
 * Thrown by RuntimeReset::betweenMessages() AFTER it has cleaned up.
 */
final class RuntimeLeakDetected extends \LogicException {}
