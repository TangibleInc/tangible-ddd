<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Commands;

/**
 * Re-enqueue a dead-lettered integration event back into the target consumer's
 * outbox (status=pending, fresh event_id), then remove the DLQ row. Read-safe:
 * a new delivery attempt, no data loss.
 *
 * @param string $consumer_prefix Bare prefix of the target consumer (e.g. 'tangible_datastream').
 * @param int    $dlq_id          integration_dlq row id to replay.
 */
final class ReplayDeadLetterCommand extends Command {

    public function __construct(
        public readonly string $consumer_prefix,
        public readonly int $dlq_id,
    ) {}
}
