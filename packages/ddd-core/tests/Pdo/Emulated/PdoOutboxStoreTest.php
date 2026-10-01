<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoOutboxStoreCases;

final class PdoOutboxStoreTest extends PdoOutboxStoreCases {
  protected static function emulatePrepares(): bool { return true; }
}
