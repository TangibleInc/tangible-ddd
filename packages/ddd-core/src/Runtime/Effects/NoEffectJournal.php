<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/** An IExternalEffectCommand reached EffectMiddleware with no IEffectJournal configured (D1). */
final class NoEffectJournal extends \LogicException {
}
