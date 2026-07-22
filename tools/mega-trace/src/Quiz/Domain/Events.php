<?php

declare(strict_types=1);

namespace Tangible\Quiz\MegaTrace\Domain\Events;

use Tangible\Quiz\MegaTrace\Domain\Aggregates\AssessmentAttempt;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;

#[Touches(Op::Created, AssessmentAttempt::class, id: 'attempt_id')]
final class DiagnosticAssessmentPrepared extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly string $attempt_id,
    ) {
    }
}

#[Touches(Op::Updated, AssessmentAttempt::class, id: 'attempt_id')]
final class DiagnosticAttemptOpened extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
    ) {
    }
}

#[Touches(Op::Updated, AssessmentAttempt::class, id: 'attempt_id')]
final class DiagnosticAttemptSubmitted extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly int $score,
    ) {
    }
}

#[Touches(Op::Updated, AssessmentAttempt::class, id: 'attempt_id')]
final class DiagnosticAssessmentGraded extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly string $attempt_id,
        public readonly int $score,
    ) {
    }
}

#[Touches(Op::Updated, AssessmentAttempt::class, id: 'attempt_id')]
final class DiagnosticSignalsAnalyzed extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly string $risk_band,
    ) {
    }
}

#[Touches(Op::Updated, AssessmentAttempt::class, id: 'attempt_id')]
final class CapstoneAttemptSubmitted extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly int $score,
    ) {
    }
}

#[Touches(Op::Updated, AssessmentAttempt::class, id: 'attempt_id')]
final class AssessmentFinalized extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly int $final_score,
    ) {
    }
}

#[Touches(Op::Updated, AssessmentAttempt::class, id: 'attempt_id')]
final class AssessmentRecordSealed extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $attempt_id,
        public readonly string $credential_id,
    ) {
    }
}
