<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Commands;

/**
 * Permanently drop a single abandoned dead-letter row. Destructive but bounded
 * to one explicitly-chosen entry; confirm-gated in the UI.
 *
 * @param string $consumer_prefix Bare prefix of the target consumer.
 * @param int    $dlq_id          integration_dlq row id to discard.
 */
final class DiscardDeadLetterCommand extends Command {

    public function __construct(
        public readonly string $consumer_prefix,
        public readonly int $dlq_id,
    ) {}
}
