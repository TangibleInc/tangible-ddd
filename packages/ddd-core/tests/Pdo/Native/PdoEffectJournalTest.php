<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Native;

use TangibleDDD\Core\Tests\Pdo\Cases\PdoEffectJournalCases;

final class PdoEffectJournalTest extends PdoEffectJournalCases {
  protected static function emulatePrepares(): bool { return false; }
}
