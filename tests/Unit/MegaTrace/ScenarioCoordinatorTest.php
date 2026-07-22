<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\MegaTrace;

require_once dirname(__DIR__, 3) . '/tools/mega-trace/autoload.php';

use PHPUnit\Framework\TestCase;
use TangibleDDD\MegaTrace\Scenario\ScenarioCoordinator;
use TangibleDDD\MegaTrace\Scenario\ScenarioLauncher;
use TangibleDDD\MegaTrace\Scenario\ScenarioPlan;
use TangibleDDD\MegaTrace\Scenario\ScenarioQueue;
use TangibleDDD\MegaTrace\Scenario\ScenarioRun;
use TangibleDDD\MegaTrace\Scenario\ScenarioState;

final class ScenarioCoordinatorTest extends TestCase
{
    public function test_start_launches_one_story_without_guessing_when_later_boundaries_are_ready(): void
    {
        $queue = new RecordingScenarioQueue();
        $state = new RecordingScenarioState();
        $launcher = new RecordingScenarioLauncher();
        $ids = ['scenario-123', 'correlation-main'];
        $coordinator = new ScenarioCoordinator(
            $queue,
            $state,
            $launcher,
            static fn (): int => 1_000,
            static function () use (&$ids): string {
                return (string) array_shift($ids);
            },
        );

        $run = $coordinator->start();

        self::assertSame('scenario-123', $run->scenario_id);
        self::assertSame('correlation-main', $run->correlation_id);
        self::assertSame([$run], $launcher->launched);
        self::assertSame($run, $state->last_run);
        self::assertSame([], $queue->single);
    }

    public function test_disable_cancels_only_the_sidecars_synthetic_actions(): void
    {
        $queue = new RecordingScenarioQueue();
        $state = new RecordingScenarioState();
        $coordinator = new ScenarioCoordinator(
            $queue,
            $state,
            new RecordingScenarioLauncher(),
            static fn (): int => 1_000,
            static fn (): string => 'unused',
        );

        $coordinator->disable();

        self::assertFalse($state->enabled);
        self::assertSame([
            [ScenarioCoordinator::SPAWN_HOOK, ScenarioCoordinator::GROUP],
            [ScenarioPlan::SUBMIT_DIAGNOSTIC, ScenarioCoordinator::GROUP],
            [ScenarioPlan::SUBMIT_CAPSTONE, ScenarioCoordinator::GROUP],
            [ScenarioPlan::RECORD_ATTESTATION, ScenarioCoordinator::GROUP],
            [ScenarioPlan::ACKNOWLEDGE_REGISTRY, ScenarioCoordinator::GROUP],
        ], $queue->cancelled);
    }

    public function test_enable_schedules_one_recurring_spawn_without_duplication(): void
    {
        $queue = new RecordingScenarioQueue();
        $state = new RecordingScenarioState();
        $coordinator = new ScenarioCoordinator(
            $queue,
            $state,
            new RecordingScenarioLauncher(),
            static fn (): int => 1_000,
            static fn (): string => 'unused',
        );

        $coordinator->enable();
        $queue->already_scheduled = true;
        $coordinator->enable();

        self::assertTrue($state->enabled);
        self::assertSame([
            [1_005, ScenarioCoordinator::SPAWN_INTERVAL, ScenarioCoordinator::SPAWN_HOOK, ScenarioCoordinator::GROUP],
        ], $queue->recurring);
    }
}

final class RecordingScenarioQueue implements ScenarioQueue
{
    /** @var list<array{int, string, array<string, string>, string}> */
    public array $single = [];

    /** @var list<array{int, int, string, string}> */
    public array $recurring = [];

    /** @var list<array{string, string}> */
    public array $cancelled = [];

    public bool $already_scheduled = false;

    public function schedule(int $timestamp, string $hook, array $args, string $group): void
    {
        $this->single[] = [$timestamp, $hook, $args, $group];
    }

    public function schedule_recurring(int $timestamp, int $interval, string $hook, string $group): void
    {
        $this->recurring[] = [$timestamp, $interval, $hook, $group];
    }

    public function has_scheduled(string $hook, string $group): bool
    {
        return $this->already_scheduled;
    }

    public function cancel_all(string $hook, string $group): void
    {
        $this->cancelled[] = [$hook, $group];
    }
}

final class RecordingScenarioState implements ScenarioState
{
    public bool $enabled = false;
    public ?ScenarioRun $last_run = null;

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function set_enabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function last_run(): ?ScenarioRun
    {
        return $this->last_run;
    }

    public function save_last_run(ScenarioRun $run): void
    {
        $this->last_run = $run;
    }
}

final class RecordingScenarioLauncher implements ScenarioLauncher
{
    /** @var list<ScenarioRun> */
    public array $launched = [];

    public function launch(ScenarioRun $run): void
    {
        $this->launched[] = $run;
    }
}
