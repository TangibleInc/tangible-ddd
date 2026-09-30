<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\DiscardDeadLetterCommand;
use TangibleDDD\Application\Support\ConsumerTables;

/** Permanently removes one dead-letter row from the target consumer's DLQ. */
final class DiscardDeadLetterHandler implements ICommandHandler {

    public function handle(ICommand $command): void {
        if (! $command instanceof DiscardDeadLetterCommand) {
            return;
        }
        global $wpdb;
        $dlq = ConsumerTables::name($command->consumer_prefix, 'integration_dlq');
        $deleted = $wpdb->delete($dlq, ['id' => $command->dlq_id]);
        if ($deleted === false) {
            throw new \RuntimeException('Failed to discard dead-letter: ' . $wpdb->last_error);
        }
        if ($deleted === 0) {
            throw new \RuntimeException("Dead-letter #{$command->dlq_id} not found in {$dlq}");
        }
    }
}
