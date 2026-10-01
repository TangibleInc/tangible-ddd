<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoConnectionCases;

final class PdoConnectionTest extends PdoConnectionCases {
  protected static function emulatePrepares(): bool { return false; }
}
