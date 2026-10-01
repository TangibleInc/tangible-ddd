<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * An await that a cancellation fact can satisfy (D3 any-of). After the
 * mechanism accumulated $event and is satisfied, a non-null reason makes the
 * runner compensate the process (as a failed await timeout does) instead of
 * resuming its next step. The reason becomes the process's failure message.
 */
interface ICancellingAwait {

  public function cancellation_reason(IIntegrationEvent $event): ?string;
}
