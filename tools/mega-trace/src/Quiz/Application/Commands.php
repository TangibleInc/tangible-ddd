<?php

declare(strict_types=1);

namespace Tangible\Quiz\MegaTrace\Application\Commands;

use Tangible\Quiz\MegaTrace\Domain\Events\AssessmentFinalized;
use Tangible\Quiz\MegaTrace\Domain\Events\AssessmentRecordSealed;
use Tangible\Quiz\MegaTrace\Domain\Events\CapstoneAttemptSubmitted;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentGraded;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentPrepared;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAttemptOpened;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAttemptSubmitted;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticSignalsAnalyzed;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\MegaTrace\Command\PublishFactCommand;
use TangibleDDD\MegaTrace\Scenario\ScenarioIds;

final class PrepareDiagnosticAssessment extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly string $attempt_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new DiagnosticAssessmentPrepared($this->journey_id, $this->learner_id, $this->attempt_id);
    }
}

final class OpenDiagnosticAttempt extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new DiagnosticAttemptOpened($this->journey_id, $this->attempt_id);
    }
}

final class SubmitDiagnosticAttempt extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly int $score,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new DiagnosticAttemptSubmitted($this->journey_id, $this->attempt_id, $this->score);
    }
}

final class GradeDiagnosticAssessment extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly int $score,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new DiagnosticAssessmentGraded(
            $this->journey_id,
            ScenarioIds::learner($this->journey_id),
            $this->attempt_id,
            $this->score,
        );
    }
}

final class AnalyzeDiagnosticSignals extends PublishFactCommand
{
    protected const SYNTHETIC_WORK_MS = 460;

    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly string $risk_band,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new DiagnosticSignalsAnalyzed($this->journey_id, $this->attempt_id, $this->risk_band);
    }
}

final class SubmitCapstoneAttempt extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly int $score,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CapstoneAttemptSubmitted($this->journey_id, $this->attempt_id, $this->score);
    }
}

final class FinalizeAssessment extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly int $final_score,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new AssessmentFinalized($this->journey_id, $this->attempt_id, $this->final_score);
    }
}

final class SealAssessmentRecord extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly string $credential_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new AssessmentRecordSealed($this->journey_id, $this->attempt_id, $this->credential_id);
    }
}
