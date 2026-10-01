<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoDeliveryLedgerCases;

final class PdoDeliveryLedgerTest extends PdoDeliveryLedgerCases {
  protected static function emulatePrepares(): bool { return true; }
}
