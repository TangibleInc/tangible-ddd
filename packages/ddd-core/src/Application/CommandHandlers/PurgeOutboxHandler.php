<?php

declare(strict_types=1);

namespace TangibleDDD\Application\CommandHandlers;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\PurgeOutboxCommand;
use TangibleDDD\Runtime\HostDefaults;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\Outbox\IOutboxAdministration;
use TangibleDDD\Runtime\Outbox\OutboxAdministrationFor;
use TangibleDDD\Runtime\SystemClock;

/**
 * Garbage-collects delivered (accepted; `completed` on wp) and aged outbox
 * rows of the target consumer: only rows processed more than `days_old`
 * days ago.
 *
 * Core form (register 1.4): orchestration over IOutboxAdministration::purge().
 */
final class PurgeOutboxHandler implements ICommandHandler {

    public function __construct(
        private readonly ?IOutboxAdministration $administration = null,
        private readonly ?IClock $clock = null,
    ) {}

    public function handle(ICommand $command): void {
        if (! $command instanceof PurgeOutboxCommand) {
            return;
        }
        $days = max(0, $command->days_old);
        $now = ($this->clock ?? HostDefaults::get(IClock::class) ?? new SystemClock())->now();

        OutboxAdministrationFor::prefix($command->consumer_prefix, $this->administration)
            ->purge($now->modify("-{$days} days"));
    }
}
