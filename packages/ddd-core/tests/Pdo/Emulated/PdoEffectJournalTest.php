<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Emulated;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoEffectJournalCases;

final class PdoEffectJournalTest extends PdoEffectJournalCases {
  protected static function emulatePrepares(): bool { return true; }
}
