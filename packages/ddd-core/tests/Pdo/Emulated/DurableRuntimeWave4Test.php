<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\DurableRuntimeWave4Cases;

final class DurableRuntimeWave4Test extends DurableRuntimeWave4Cases {
  protected static function emulatePrepares(): bool { return true; }
}
