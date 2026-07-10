<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ReplayDeadLetterCommand;
use TangibleDDD\Application\Support\ConsumerTables;

/**
 * Copies a dead-letter's stored payload back into the target consumer's outbox
 * as a fresh pending row (data copy — no PHP event reconstruction), then removes
 * the DLQ row. The outbox drain re-attempts delivery through its own machinery.
 */
final class ReplayDeadLetterHandler implements ICommandHandler {

    public function handle(ICommand $command): void {
        if (! $command instanceof ReplayDeadLetterCommand) {
            return;
        }
        global $wpdb;
        $dlq = ConsumerTables::name($command->consumer_prefix, 'integration_dlq');
        $ob  = ConsumerTables::name($command->consumer_prefix, 'integration_outbox');

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$dlq}` WHERE id = %d", $command->dlq_id), ARRAY_A);
        if (! $row) {
            throw new \RuntimeException("Dead-letter #{$command->dlq_id} not found in {$dlq}");
        }

        $now = gmdate('Y-m-d H:i:s');
        $ok  = $wpdb->insert($ob, [
            'event_id'           => wp_generate_uuid4(),
            'event_type'         => $row['event_type'],
            'integration_action' => $row['integration_action'],
            'message_kind'       => 'event',
            'transport'          => 'action_scheduler',
            'queue'              => null,
            'payload_bytes'      => strlen((string) $row['payload']),
            'correlation_id'     => $row['correlation_id'],
            'sequence'           => 0,
            'command_id'         => $row['command_id'],
            'payload'            => $row['payload'],
            'delay_seconds'      => 0,
            'scheduled_at'       => $now,
            'is_unique'          => 0,
            'status'             => 'pending',
            'attempts'           => 0,
            'max_attempts'       => 5,
            'next_attempt_at'    => $now,
            'created_at'         => $now,
            'blog_id'            => $row['blog_id'],
        ]);
        if ($ok === false) {
            throw new \RuntimeException('Failed to re-enqueue dead-letter: ' . $wpdb->last_error);
        }

        $wpdb->delete($dlq, ['id' => $command->dlq_id]);
    }
}
