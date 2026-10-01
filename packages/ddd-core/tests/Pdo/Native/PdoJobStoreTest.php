<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoJobStoreCases;

final class PdoJobStoreTest extends PdoJobStoreCases {
  protected static function emulatePrepares(): bool { return false; }
}
