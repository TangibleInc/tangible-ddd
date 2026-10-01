<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoOperatorViewCases;

final class PdoOperatorViewTest extends PdoOperatorViewCases {
  protected static function emulatePrepares(): bool { return true; }
}
