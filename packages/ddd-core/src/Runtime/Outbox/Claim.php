<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/**
 * A leased outbox row (register 3.4). Every follow-up write (accept,
 * retryLater, deadLetter) is fenced by `event_id` AND `claimToken`.
 * `attempts` is the relay-submission count before this claim.
 */
final class Claim {

  public function __construct(
    public readonly string $event_id,
    public readonly string $claimToken,
    public readonly \DateTimeImmutable $leaseUntil,
    public readonly OutboxRecord $record,
    public readonly int $attempts,
  ) {}
}
