<?php

declare(strict_types=1);

namespace Tangible\Quiz\MegaTrace\Application\Process;

use Tangible\Quiz\MegaTrace\Application\Commands\AnalyzeDiagnosticSignals;
use Tangible\Quiz\MegaTrace\Application\Commands\FinalizeAssessment;
use Tangible\Quiz\MegaTrace\Application\Commands\OpenDiagnosticAttempt;
use Tangible\Quiz\MegaTrace\Domain\Events\CapstoneAttemptSubmitted;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentPrepared;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAttemptSubmitted;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;

#[StartsOn(DiagnosticAssessmentPrepared::class)]
#[Awaits(DiagnosticAttemptSubmitted::class)]
#[Awaits(CapstoneAttemptSubmitted::class)]
final class AdaptiveAssessmentProcess extends LongProcess
{
    public function __construct(
        private readonly string $journey_id,
        private readonly string $attempt_id,
    ) {
        parent::__construct(null);
    }

    public static function from_event(DiagnosticAssessmentPrepared $event): ?static
    {
        return new static($event->journey_id, $event->attempt_id);
    }

    protected function initialize(): Result
    {
        $state = new AdaptiveAssessmentState(
            $this->journey_id,
            $this->attempt_id,
        );

        return new Result(payload: $state, commands: [
            new OpenDiagnosticAttempt($state->journey_id, $state->attempt_id),
        ]);
    }

    protected function wait_for_diagnostic(AdaptiveAssessmentState $state): Result
    {
        return new Result(
            payload: $state,
            await: new AwaitEvent(DiagnosticAttemptSubmitted::class, ['journey_id' => $state->journey_id]),
        );
    }

    protected function analyze_signals(
        AdaptiveAssessmentState $state,
        DiagnosticAttemptSubmitted $attempt,
    ): Result {
        $risk = $attempt->score >= 75 ? 'standard' : 'supported';
        return new Result(payload: $state, commands: [
            new AnalyzeDiagnosticSignals($state->journey_id, $state->attempt_id, $risk),
        ]);
    }

    protected function wait_for_capstone(AdaptiveAssessmentState $state): Result
    {
        return new Result(
            payload: $state,
            await: new AwaitEvent(CapstoneAttemptSubmitted::class, ['journey_id' => $state->journey_id]),
        );
    }

    protected function finalize_assessment(
        AdaptiveAssessmentState $state,
        CapstoneAttemptSubmitted $attempt,
    ): Result {
        return new Result(commands: [
            new FinalizeAssessment($state->journey_id, $state->attempt_id, $attempt->score),
        ]);
    }
}

final class AdaptiveAssessmentState extends DirectJsonLifecycleValue
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
    ) {
        parent::__construct();
    }

    protected static function from_json_instance(\stdClass|array $rendered_data, ...$params): static
    {
        $data = (array) $rendered_data;
        return new static((string) $data['journey_id'], (string) $data['attempt_id']);
    }
}
