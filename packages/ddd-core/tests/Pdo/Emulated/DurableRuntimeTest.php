<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\DurableRuntimeCases;

final class DurableRuntimeTest extends DurableRuntimeCases {
  protected static function emulatePrepares(): bool { return true; }
}
