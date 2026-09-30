<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/** One DLQ row as IOutboxAdministration::deadLetters() lists it. */
final class DeadLetter {

  public function __construct(
    public readonly int $dlqId,
    public readonly string $event_id,
    public readonly string $error,
    public readonly int $attempts,
    public readonly \DateTimeImmutable $deadLetteredAt,
    public readonly OutboxRecord $record,
  ) {}
}
