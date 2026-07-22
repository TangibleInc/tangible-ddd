<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Scenario;

use Closure;

final class ScenarioCoordinator
{
    public const GROUP = 'tddd-mega-trace';
    public const SPAWN_HOOK = 'tddd_mega_trace_spawn';
    public const SPAWN_INTERVAL = 600;

    private Closure $now;
    private Closure $next_id;

    public function __construct(
        private readonly ScenarioQueue $queue,
        private readonly ScenarioState $state,
        private readonly ScenarioLauncher $launcher,
        callable $now,
        callable $next_id,
    ) {
        $this->now = Closure::fromCallable($now);
        $this->next_id = Closure::fromCallable($next_id);
    }

    public function start(): ScenarioRun
    {
        $run = new ScenarioRun(
            ($this->next_id)(),
            ($this->next_id)(),
            ($this->now)(),
        );

        $this->launcher->launch($run);
        $this->state->save_last_run($run);

        return $run;
    }

    public function disable(): void
    {
        $this->state->set_enabled(false);

        $this->queue->cancel_all(self::SPAWN_HOOK, self::GROUP);
        foreach (ScenarioPlan::boundary_hooks() as $hook) {
            $this->queue->cancel_all($hook, self::GROUP);
        }
    }

    public function enable(): void
    {
        $this->state->set_enabled(true);
        if ($this->queue->has_scheduled(self::SPAWN_HOOK, self::GROUP)) {
            return;
        }

        $this->queue->schedule_recurring(
            ($this->now)() + 5,
            self::SPAWN_INTERVAL,
            self::SPAWN_HOOK,
            self::GROUP,
        );
    }
}
