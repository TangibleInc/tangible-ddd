<?php

declare(strict_types=1);

namespace Tangible\LMS\MegaTrace\Domain\Events;

use Tangible\LMS\MegaTrace\Domain\Aggregates\LearningJourney;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;

#[Touches(Op::Created, LearningJourney::class, id: 'journey_id')]
final class CertificationJourneyLaunched extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly int $course_id,
    ) {
    }
}

#[Touches(Op::Updated, LearningJourney::class, id: 'journey_id')]
final class JourneyPlanRecorded extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
    ) {
    }
}

#[Touches(Op::Updated, LearningJourney::class, id: 'journey_id')]
final class LearningPathPersonalized extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly int $module_count,
    ) {
    }
}

#[Touches(Op::Updated, LearningJourney::class, id: 'journey_id')]
final class AdaptiveModulesUnlocked extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly int $diagnostic_score,
    ) {
    }
}

#[Touches(Op::Updated, LearningJourney::class, id: 'journey_id')]
final class CertificationJourneyCompleted extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly int $final_score,
    ) {
    }
}

#[Touches(Op::Updated, LearningJourney::class, id: 'journey_id')]
final class CredentialAttachedToJourney extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $credential_id,
    ) {
    }
}

#[Touches(Op::Updated, LearningJourney::class, id: 'journey_id')]
final class CertificationRecordArchived extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $credential_id,
        public readonly string $receipt_id,
    ) {
    }
}
