<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\SchemaCheckCases;

final class SchemaCheckTest extends SchemaCheckCases {
  protected static function emulatePrepares(): bool { return true; }
}
