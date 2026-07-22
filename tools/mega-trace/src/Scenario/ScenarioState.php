<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Scenario;

interface ScenarioState
{
    public function enabled(): bool;

    public function set_enabled(bool $enabled): void;

    public function last_run(): ?ScenarioRun;

    public function save_last_run(ScenarioRun $run): void;
}
