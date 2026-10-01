<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\DiscardDeadLetterCommand;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationFor;

/**
 * Permanently removes one dead-letter row from the target consumer's DLQ.
 *
 * Core form (register 1.4): orchestration over IOutboxAdministration (the
 * host's for the command's consumer prefix unless one is injected).
 * Error behaviour: OutboxRowNotFound for an unknown dlq id; storage
 * failures throw.
 */
final class DiscardDeadLetterHandler implements ICommandHandler {

    public function __construct(private readonly ?IOutboxAdministration $administration = null) {}

    public function handle(ICommand $command): void {
        if (! $command instanceof DiscardDeadLetterCommand) {
            return;
        }
        OutboxAdministrationFor::prefix($command->consumer_prefix, $this->administration)->discard($command->dlq_id);
    }
}
