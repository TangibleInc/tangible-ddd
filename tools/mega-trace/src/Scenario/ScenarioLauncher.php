<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Scenario;

interface ScenarioLauncher
{
    public function launch(ScenarioRun $run): void;
}
