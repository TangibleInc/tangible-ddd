<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Infra;

use TangibleDDD\MegaTrace\Scenario\ScenarioRun;
use TangibleDDD\MegaTrace\Scenario\ScenarioState;

final class WordPressScenarioState implements ScenarioState
{
    private const OPTION = 'tddd_mega_trace_state';

    public function enabled(): bool
    {
        return (bool) ($this->read()['enabled'] ?? false);
    }

    public function set_enabled(bool $enabled): void
    {
        $state = $this->read();
        $state['enabled'] = $enabled;
        update_option(self::OPTION, $state, false);
    }

    public function last_run(): ?ScenarioRun
    {
        $run = $this->read()['last_run'] ?? null;
        if (!is_array($run)) {
            return null;
        }

        $scenario = $run['scenario_id'] ?? null;
        $correlation = $run['correlation_id'] ?? null;
        $started = $run['started_at'] ?? null;
        if (!is_string($scenario) || !is_string($correlation) || !is_numeric($started)) {
            return null;
        }

        return new ScenarioRun($scenario, $correlation, (int) $started);
    }

    public function save_last_run(ScenarioRun $run): void
    {
        $state = $this->read();
        $state['last_run'] = [
            'scenario_id' => $run->scenario_id,
            'correlation_id' => $run->correlation_id,
            'started_at' => $run->started_at,
        ];
        update_option(self::OPTION, $state, false);
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        $state = get_option(self::OPTION, []);
        return is_array($state) ? $state : [];
    }
}
