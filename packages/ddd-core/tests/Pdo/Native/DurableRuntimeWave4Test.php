<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use TangibleDDD\Core\Tests\Pdo\Cases\DurableRuntimeWave4Cases;

final class DurableRuntimeWave4Test extends DurableRuntimeWave4Cases {
  protected static function emulatePrepares(): bool { return false; }
}
