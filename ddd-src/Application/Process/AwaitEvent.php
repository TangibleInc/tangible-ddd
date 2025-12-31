<?php

namespace TangibleDDD\Application\Process;

use InvalidArgumentException;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Marker for suspending a process until an integration event fires.
 *
 * When a step returns Result with an AwaitEvent, the process suspends
 * and resumes when a matching event is published.
 *
 * IMPORTANT: Any event class used with AwaitEvent must be declared in the
 * process's DI service tag so the framework can wire up the action hooks.
 *
 * In your services.yaml:
 * ```yaml
 * App\Process\OrderFulfillmentProcess:
 *   tags:
 *     - name: 'ddd.long_process'
 *       awaits:
 *         - App\Events\PaymentReceived
 *         - App\Events\UserApproved
 * ```
 *
 * If you forget to declare an awaited event, the process will suspend
 * but never resume (the action hook won't be registered).
 */
final class AwaitEvent {
  /** @var class-string<IIntegrationEvent> */
  public readonly string $event_class;

  public function __construct(
    string $event_class,

    /** Criteria to match against event properties */
    public readonly array $match_criteria = [],
  ) {
    if (!is_a($event_class, IIntegrationEvent::class, true)) {
      throw new InvalidArgumentException(
        "AwaitEvent expects an IIntegrationEvent class, got: $event_class"
      );
    }

    $this->event_class = $event_class;
  }
}
