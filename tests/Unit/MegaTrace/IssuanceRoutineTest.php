<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use PHPUnit\Framework\TestCase;
use Tangible\Cred\MegaTrace\Application\BehaviourWorkflows\IssuanceRoutine;
use Tangible\Cred\MegaTrace\Application\Commands\RunIssuanceRoutine;
use Tangible\Cred\MegaTrace\Domain\Events\IssuanceRoutineItemCompleted;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Events\IIntegrationEventBus;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\ValueObjects\Behaviours\WorkItemStatus;
use TangibleDDD\Tests\Fakes\FakeBehaviourWorkflowRepository;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeWorkItemRepository;

final class IssuanceRoutineTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_test_scheduled_actions'] = [];
        Correlation::reset();
    }

    protected function tearDown(): void
    {
        Correlation::reset();
    }

    public function test_one_item_runs_per_command_and_continuation_keeps_the_story(): void
    {
        $workflows = new FakeBehaviourWorkflowRepository();
        $items = new FakeWorkItemRepository();
        $events = new RecordingIntegrationBus();
        $routine = new IssuanceRoutine($workflows, $items, new FakeDDDConfig(), $events);
        $command = new RunIssuanceRoutine('journey-1', 42, 'portfolio-1');

        Correlation::within(
            new TraceContext('correlation-main'),
            static fn () => $routine->handle($command),
        );

        self::assertCount(3, $items->store);
        self::assertSame(
            [WorkItemStatus::done, WorkItemStatus::pending, WorkItemStatus::pending],
            array_map(static fn ($item) => $item->status, array_values($items->store)),
        );
        self::assertCount(1, $events->published);
        self::assertInstanceOf(IssuanceRoutineItemCompleted::class, $events->published[0]);
        self::assertSame('identity', $events->published[0]->item_key);
        self::assertSame('test-behaviours', $GLOBALS['_test_scheduled_actions'][0]['group']);
        self::assertSame(
            'correlation-main',
            $GLOBALS['_test_scheduled_actions'][0]['args']['correlation_id'],
        );
    }
}

final class RecordingIntegrationBus implements IIntegrationEventBus
{
    /** @var list<IIntegrationEvent> */
    public array $published = [];

    public function publish(IIntegrationEvent $event): void
    {
        $this->published[] = $event;
    }
}
