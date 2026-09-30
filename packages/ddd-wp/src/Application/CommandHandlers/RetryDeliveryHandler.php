<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\RetryDeliveryCommand;
use TangibleDDD\Application\Support\ConsumerTables;

/** Resets a failed/dlq'd outbox row to pending so the drain re-attempts it. */
final class RetryDeliveryHandler implements ICommandHandler {

    public function handle(ICommand $command): void {
        if (! $command instanceof RetryDeliveryCommand) {
            return;
        }
        global $wpdb;
        $ob  = ConsumerTables::name($command->consumer_prefix, 'integration_outbox');
        $now = gmdate('Y-m-d H:i:s');
        $affected = $wpdb->update(
            $ob,
            [
                'status'          => 'pending',
                'attempts'        => 0,
                'next_attempt_at' => $now,
                'locked_until'    => null,
                'locked_by'       => null,
                'last_error'      => null,
            ],
            ['id' => $command->outbox_id]
        );
        if ($affected === false) {
            throw new \RuntimeException('Failed to retry delivery: ' . $wpdb->last_error);
        }
        if ($affected === 0) {
            throw new \RuntimeException("Outbox row #{$command->outbox_id} not found in {$ob}");
        }
    }
}
