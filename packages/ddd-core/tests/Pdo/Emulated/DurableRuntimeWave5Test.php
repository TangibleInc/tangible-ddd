<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\DurableRuntimeWave5Cases;

final class DurableRuntimeWave5Test extends DurableRuntimeWave5Cases {
  protected static function emulatePrepares(): bool { return true; }
}
