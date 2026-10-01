<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use TangibleDDD\Core\Tests\Pdo\Cases\DurableRuntimeWave5Cases;

final class DurableRuntimeWave5Test extends DurableRuntimeWave5Cases {
  protected static function emulatePrepares(): bool { return false; }
}
