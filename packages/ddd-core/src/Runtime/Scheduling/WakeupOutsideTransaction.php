<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/** schedule()/cancel() was called with no active transaction on the process store's connection. */
final class WakeupOutsideTransaction extends \LogicException {}
