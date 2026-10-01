<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoPauseStoreCases;

final class PdoPauseStoreTest extends PdoPauseStoreCases {
  protected static function emulatePrepares(): bool { return true; }
}
