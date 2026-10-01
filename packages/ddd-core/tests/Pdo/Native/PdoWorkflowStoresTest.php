<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoWorkflowStoresCases;

final class PdoWorkflowStoresTest extends PdoWorkflowStoresCases {
  protected static function emulatePrepares(): bool { return false; }
}
