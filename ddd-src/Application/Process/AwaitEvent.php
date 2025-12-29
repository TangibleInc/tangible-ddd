<?php

namespace TangibleDDD\Application\Process;

use InvalidArgumentException;
use TangibleDDD\Domain\Events\IIntegrationEvent;

/**
 * Marker for suspending a process until an integration event fires.
 *
 * When a step returns Result with an AwaitEvent, the process suspends
 * and resumes when a matching event is published.
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
