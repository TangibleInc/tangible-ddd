<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Outbox\OutboxRecord;

/** Default IFactObserver outside WordPress: observes nothing (O4). */
final class NullFactObserver implements IFactObserver {

  public function observe(IIntegrationEvent $e, OutboxRecord $r): void {}
}
