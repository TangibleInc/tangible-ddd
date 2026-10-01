<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Commands;

/**
 * Garbage-collect COMPLETED outbox rows older than N days from the target
 * consumer's outbox (wires the previously-dead OutboxRepository::purge_completed
 * intent). Destructive but bounded to delivered+aged rows; confirm-gated.
 *
 * @param string $consumer_prefix Bare prefix of the target consumer.
 * @param int    $days_old        Age threshold in days (default 30).
 */
final class PurgeOutboxCommand extends Command {

    public function __construct(
        public readonly string $consumer_prefix,
        public readonly int $days_old = 30,
    ) {}
}
