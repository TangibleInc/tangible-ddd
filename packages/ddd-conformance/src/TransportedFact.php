<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance;

/** One message the host transport holds (AS action, Messenger envelope, jobs row, mem submission). */
final class TransportedFact {

  public function __construct(
    public readonly string $event_id,
    /** the ABSOLUTE UTC time the transport will deliver at */
    public readonly \DateTimeImmutable $due_at,
  ) {}
}
