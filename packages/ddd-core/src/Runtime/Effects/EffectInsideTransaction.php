<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/**
 * perform() would run inside an open transaction (D1): refused before the
 * external call, since a rollback could not undo it.
 */
final class EffectInsideTransaction extends \LogicException {
}
