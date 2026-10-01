<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;

/**
 * Optional HostFixture read-back of the infrastructure signals the host
 * emitted (AuditSinkFailed, OutboxDeadLettered, ...), wave-2 round-3 change
 * request CR-CC-1. Separate from HostFixture for the same reason as
 * AuditSinkFaults.
 *
 * mem records them with a RecordingSignalDispatcher in HostDefaults; wp can
 * listen on the global `tangible_ddd_{action}` hooks; sf on its dispatcher.
 */
interface RecordsSignals {

  /** @return list<IInfrastructureEvent> signals emitted since set_up(), oldest first */
  public function signals(): array;
}
