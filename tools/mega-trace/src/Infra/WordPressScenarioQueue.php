<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Infra;

use TangibleDDD\MegaTrace\Scenario\ScenarioQueue;

final class WordPressScenarioQueue implements ScenarioQueue
{
    public function schedule(int $timestamp, string $hook, array $args, string $group): void
    {
        as_schedule_single_action($timestamp, $hook, $args, $group, true);
    }

    public function schedule_recurring(int $timestamp, int $interval, string $hook, string $group): void
    {
        as_schedule_recurring_action($timestamp, $interval, $hook, [], $group, true);
    }

    public function has_scheduled(string $hook, string $group): bool
    {
        return as_has_scheduled_action($hook, null, $group) !== false;
    }

    public function cancel_all(string $hook, string $group): void
    {
        as_unschedule_all_actions($hook, null, $group);
    }
}
