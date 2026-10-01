<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoRelayCases;

final class PdoRelayTest extends PdoRelayCases {
  protected static function emulatePrepares(): bool { return true; }
}
