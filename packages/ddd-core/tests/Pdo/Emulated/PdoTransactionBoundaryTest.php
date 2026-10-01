<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoTransactionBoundaryCases;

final class PdoTransactionBoundaryTest extends PdoTransactionBoundaryCases {
  protected static function emulatePrepares(): bool { return true; }
}
