<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

/** The wake target has no door for this intent kind. Not retryable: the intent is exhausted for the operator. */
final class WakeKindUnsupported extends \LogicException {}
