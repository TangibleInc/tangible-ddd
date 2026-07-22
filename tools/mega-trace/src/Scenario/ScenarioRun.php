<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Scenario;

final class ScenarioRun
{
    public function __construct(
        public readonly string $scenario_id,
        public readonly string $correlation_id,
        public readonly int $started_at,
    ) {
    }

    public function journey_id(): string
    {
        return $this->scenario_id;
    }

    public function reference_id(): int
    {
        return (int) sprintf('%u', crc32($this->scenario_id));
    }

    /** @return array{scenario_id: string, journey_id: string} */
    public function action_args(): array
    {
        return [
            'scenario_id' => $this->scenario_id,
            'journey_id' => $this->journey_id(),
        ];
    }
}
