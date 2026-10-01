<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/**
 * An IEffectCommand that is not self-contained reached EffectMiddleware and
 * no IExternalEffectHandler could be located for it (no handler locator, no
 * such service, or a service of another kind). Thrown before perform() (E1).
 */
final class NoEffectHandler extends \LogicException {
}
