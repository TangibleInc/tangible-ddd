<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Admin\Dashboard;

use Closure;
use InvalidArgumentException;
use TangibleDDD\Application\Commands\DiscardDeadLetterCommand;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\PurgeOutboxCommand;
use TangibleDDD\Application\Commands\ReplayDeadLetterCommand;
use TangibleDDD\Application\Commands\RetryDeliveryCommand;

final class ActionDispatcher
{
    private readonly Closure $send;

    public function __construct(?callable $send = null)
    {
        $this->send = $send !== null
            ? Closure::fromCallable($send)
            : static fn (ICommand $command): mixed => $command->send();
    }

    /** @return array{ok: true, action: string} */
    public function dispatch(string $action, string $consumerPrefix, int $id, int $days = 30): array
    {
        $command = match ($action) {
            'replay' => new ReplayDeadLetterCommand($consumerPrefix, $id),
            'discard' => new DiscardDeadLetterCommand($consumerPrefix, $id),
            'retry' => new RetryDeliveryCommand($consumerPrefix, $id),
            'purge' => new PurgeOutboxCommand($consumerPrefix, $days),
            default => throw new InvalidArgumentException("unknown action: {$action}"),
        };

        ($this->send)($command);
        return ['ok' => true, 'action' => $action];
    }
}
