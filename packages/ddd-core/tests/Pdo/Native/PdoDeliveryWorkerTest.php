<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoDeliveryWorkerCases;

final class PdoDeliveryWorkerTest extends PdoDeliveryWorkerCases {
  protected static function emulatePrepares(): bool { return false; }
}
