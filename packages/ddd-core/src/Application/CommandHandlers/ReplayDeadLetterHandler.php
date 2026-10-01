<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ReplayDeadLetterCommand;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationFor;

/**
 * Puts a dead letter back into the target consumer's outbox and removes the
 * DLQ row; the relay re-attempts delivery through its own machinery.
 *
 * Core form (register 1.4, 3.4 / C22): orchestration over
 * IOutboxAdministration::replay(), which KEEPS the event_id (resets the
 * original row, or re-inserts it with the original id). 0.6 minted a fresh
 * UUID, so replaying an igniting fact ignited a second process; the 0.6.7
 * hotfix keeps that behaviour (register section 6).
 */
final class ReplayDeadLetterHandler implements ICommandHandler {

    public function __construct(private readonly ?IOutboxAdministration $administration = null) {}

    public function handle(ICommand $command): void {
        if (! $command instanceof ReplayDeadLetterCommand) {
            return;
        }
        OutboxAdministrationFor::prefix($command->consumer_prefix, $this->administration)->replay($command->dlq_id);
    }
}
