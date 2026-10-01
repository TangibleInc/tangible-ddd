<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoOutboxAdministrationCases;

final class PdoOutboxAdministrationTest extends PdoOutboxAdministrationCases {
  protected static function emulatePrepares(): bool { return false; }
}
