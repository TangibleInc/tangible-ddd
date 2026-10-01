<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Commands;

/**
 * Reset a failed/dlq'd outbox row back to pending for another delivery attempt
 * (attempts=0, locks + next_attempt cleared). Read-safe.
 *
 * @param string $consumer_prefix Bare prefix of the target consumer.
 * @param int    $outbox_id       integration_outbox row id to retry.
 */
final class RetryDeliveryCommand extends Command {

    public function __construct(
        public readonly string $consumer_prefix,
        public readonly int $outbox_id,
    ) {}
}
