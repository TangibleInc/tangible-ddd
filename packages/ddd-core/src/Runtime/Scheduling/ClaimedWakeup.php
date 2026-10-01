<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Scheduling;

/** A leased due intent; complete()/retry_later() are fenced by token. */
final class ClaimedWakeup {

  public function __construct(
    public readonly WakeupIntent $intent,
    public readonly string $token,
    public readonly \DateTimeImmutable $lease_until,
    public readonly int $attempts,
  ) {}
}
