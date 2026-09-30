<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/** A leased due intent; complete()/retryLater() are fenced by claimToken. */
final class ClaimedWakeup {

  public function __construct(
    public readonly WakeupIntent $intent,
    public readonly string $claimToken,
    public readonly \DateTimeImmutable $leaseUntil,
    public readonly int $attempts,
  ) {}
}
