<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\MySqlNamedLockCases;

final class MySqlNamedLockTest extends MySqlNamedLockCases {
  protected static function emulatePrepares(): bool { return true; }
}
