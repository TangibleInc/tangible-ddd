<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\PurgeOutboxCommand;
use TangibleDDD\Application\Support\ConsumerTables;

/**
 * Garbage-collects COMPLETED + aged outbox rows from the target consumer.
 * Bounded: only status='completed' AND processed_at older than the threshold.
 */
final class PurgeOutboxHandler implements ICommandHandler {

    public function handle(ICommand $command): void {
        if (! $command instanceof PurgeOutboxCommand) {
            return;
        }
        global $wpdb;
        $ob     = ConsumerTables::name($command->consumer_prefix, 'integration_outbox');
        $days   = max(0, $command->days_old);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$ob}` WHERE status = 'completed' AND processed_at IS NOT NULL AND processed_at < %s",
            $cutoff
        ));
        if ($result === false) {
            throw new \RuntimeException('Failed to purge outbox: ' . $wpdb->last_error);
        }
    }
}
