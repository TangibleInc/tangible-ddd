<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/**
 * A leased outbox row (register 3.4). Every follow-up write (accept,
 * retry_later, dead_letter) is fenced by `event_id` AND `token`.
 * `attempts` is the relay-submission count before this claim.
 */
final class Claim {

  public function __construct(
    public readonly string $event_id,
    public readonly string $token,
    public readonly \DateTimeImmutable $lease_until,
    public readonly OutboxRecord $record,
    public readonly int $attempts,
  ) {}
}
