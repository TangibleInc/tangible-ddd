<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Scenario;

interface ScenarioQueue
{
    /** @param array<string, string> $args */
    public function schedule(int $timestamp, string $hook, array $args, string $group): void;

    public function schedule_recurring(int $timestamp, int $interval, string $hook, string $group): void;

    public function has_scheduled(string $hook, string $group): bool;

    public function cancel_all(string $hook, string $group): void;
}
