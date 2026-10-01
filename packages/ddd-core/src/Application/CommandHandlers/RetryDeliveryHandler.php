<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\RetryDeliveryCommand;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\IOutboxRowIds;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationFor;
use TangibleDDD\Runtime\Outbox\OutboxRowNotFound;

/**
 * Resets a failed or dead-lettered outbox row to pending so the relay
 * re-attempts it.
 *
 * Core form (register 1.4, 3.4): the shipped command keeps its integer
 * `outbox_id`; the handler maps it to the row's event_id (IOutboxRowIds)
 * and calls IOutboxAdministration::retry(), which refuses a leased row and,
 * unforced, any row that is not pending/dlq (O5; 0.6 reset any row).
 *
 * Error behaviour: OutboxRowNotFound for an unknown id;
 * OutboxAdministrationRefused per retry(); \LogicException when the
 * administration has no integer ids (not IOutboxRowIds).
 */
final class RetryDeliveryHandler implements ICommandHandler {

    public function __construct(private readonly ?IOutboxAdministration $administration = null) {}

    public function handle(ICommand $command): void {
        if (! $command instanceof RetryDeliveryCommand) {
            return;
        }
        $admin = OutboxAdministrationFor::prefix($command->consumer_prefix, $this->administration);
        if (! $admin instanceof IOutboxRowIds) {
            throw new \LogicException(get_class($admin) . ' has no integer outbox ids; RetryDeliveryCommand needs an ' . IOutboxRowIds::class);
        }

        $event_id = $admin->eventIdOf($command->outbox_id)
            ?? throw new OutboxRowNotFound("Outbox row #{$command->outbox_id} not found for consumer {$command->consumer_prefix}");

        $admin->retry($event_id);
    }
}
