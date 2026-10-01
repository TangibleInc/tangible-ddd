<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoProcessStoreCases;

final class PdoProcessStoreTest extends PdoProcessStoreCases {
  protected static function emulatePrepares(): bool { return false; }
}
