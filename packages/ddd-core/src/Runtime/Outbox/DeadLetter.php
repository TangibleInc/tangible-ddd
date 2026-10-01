<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/** One DLQ row as IOutboxAdministration::dead_letters() lists it. */
final class DeadLetter {

  public function __construct(
    public readonly int $dlq_id,
    public readonly string $event_id,
    public readonly string $error,
    public readonly int $attempts,
    public readonly \DateTimeImmutable $dead_lettered_at,
    public readonly OutboxRecord $record,
  ) {}
}
