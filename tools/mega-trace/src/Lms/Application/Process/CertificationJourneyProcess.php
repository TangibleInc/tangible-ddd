<?php

declare(strict_types=1);

namespace Tangible\LMS\MegaTrace\Application\Process;

use Tangible\LMS\MegaTrace\Application\Commands\CompleteCertificationJourney;
use Tangible\LMS\MegaTrace\Application\Commands\PersonalizeLearningPath;
use Tangible\LMS\MegaTrace\Application\Commands\RecordJourneyPlan;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyLaunched;
use Tangible\Quiz\MegaTrace\Application\Commands\GradeDiagnosticAssessment;
use Tangible\Quiz\MegaTrace\Application\Commands\PrepareDiagnosticAssessment;
use Tangible\Quiz\MegaTrace\Domain\Events\CapstoneAttemptSubmitted;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAttemptSubmitted;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;
use TangibleDDD\MegaTrace\Scenario\ScenarioIds;

#[StartsOn(CertificationJourneyLaunched::class)]
#[Awaits(DiagnosticAttemptSubmitted::class)]
#[Awaits(CapstoneAttemptSubmitted::class)]
final class CertificationJourneyProcess extends LongProcess
{
    public function __construct(
        private readonly string $journey_id,
        private readonly int $learner_id,
        private readonly int $course_id,
        private readonly string $attempt_id,
    ) {
        parent::__construct(null);
    }

    public static function from_event(CertificationJourneyLaunched $event): ?static
    {
        return new static(
            $event->journey_id,
            $event->learner_id,
            $event->course_id,
            ScenarioIds::attempt($event->journey_id),
        );
    }

    protected function initialize(): Result
    {
        $state = new CertificationJourneyState(
            $this->journey_id,
            $this->learner_id,
            $this->course_id,
            $this->attempt_id,
        );

        return new Result(payload: $state, commands: [
            new RecordJourneyPlan($state->journey_id, $state->learner_id),
            new PrepareDiagnosticAssessment(
                $state->journey_id,
                $state->learner_id,
                $state->attempt_id,
            ),
        ]);
    }

    protected function wait_for_diagnostic(CertificationJourneyState $state): Result
    {
        return new Result(
            payload: $state,
            await: new AwaitEvent(DiagnosticAttemptSubmitted::class, ['journey_id' => $state->journey_id]),
        );
    }

    protected function shape_learning_path(
        CertificationJourneyState $state,
        DiagnosticAttemptSubmitted $attempt,
    ): Result {
        return new Result(payload: $state, commands: [
            new PersonalizeLearningPath($state->journey_id, $state->learner_id, 6),
            new GradeDiagnosticAssessment($state->journey_id, $state->attempt_id, $attempt->score),
        ]);
    }

    protected function wait_for_capstone(CertificationJourneyState $state): Result
    {
        return new Result(
            payload: $state,
            await: new AwaitEvent(CapstoneAttemptSubmitted::class, ['journey_id' => $state->journey_id]),
        );
    }

    protected function complete_journey(
        CertificationJourneyState $state,
        CapstoneAttemptSubmitted $attempt,
    ): Result {
        return new Result(commands: [
            new CompleteCertificationJourney($state->journey_id, $state->learner_id, $attempt->score),
        ]);
    }
}

final class CertificationJourneyState extends DirectJsonLifecycleValue
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly int $course_id,
        public readonly string $attempt_id,
    ) {
        parent::__construct();
    }

    protected static function from_json_instance(\stdClass|array $rendered_data, ...$params): static
    {
        $data = (array) $rendered_data;
        return new static(
            (string) $data['journey_id'],
            (int) $data['learner_id'],
            (int) $data['course_id'],
            (string) $data['attempt_id'],
        );
    }
}
