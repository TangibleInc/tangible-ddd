<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Dashboard;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Commands\DiscardDeadLetterCommand;
use TangibleDDD\Application\Commands\PurgeOutboxCommand;
use TangibleDDD\Application\Commands\ReplayDeadLetterCommand;
use TangibleDDD\Application\Commands\RetryDeliveryCommand;
use TangibleDDD\WordPress\Admin\Dashboard\ActionDispatcher;

final class ActionDispatcherTest extends TestCase
{
    public static function actions(): array
    {
        return [
            ['replay', ReplayDeadLetterCommand::class, 'dlq_id', 11],
            ['discard', DiscardDeadLetterCommand::class, 'dlq_id', 11],
            ['retry', RetryDeliveryCommand::class, 'outbox_id', 11],
            ['purge', PurgeOutboxCommand::class, 'days_old', 45],
        ];
    }

    #[DataProvider('actions')]
    public function test_it_maps_v1_actions_to_framework_commands(
        string $action,
        string $expectedClass,
        string $valueProperty,
        int $expectedValue
    ): void {
        $sent = null;
        $dispatcher = new ActionDispatcher(static function (object $command) use (&$sent): void {
            $sent = $command;
        });

        $result = $dispatcher->dispatch($action, 'consumer_prefix', 11, 45);

        self::assertInstanceOf($expectedClass, $sent);
        self::assertSame('consumer_prefix', $sent->consumer_prefix);
        self::assertSame($expectedValue, $sent->{$valueProperty});
        self::assertSame(['ok' => true, 'action' => $action], $result);
    }

    public function test_it_rejects_unknown_actions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ActionDispatcher(static fn (): null => null))->dispatch('explode', 'test', 1, 30);
    }
}
