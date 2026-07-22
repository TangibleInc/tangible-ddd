<?php

declare(strict_types=1);

namespace Tangible\LMS\MegaTrace\Application\Commands;

use Tangible\LMS\MegaTrace\Domain\Events\AdaptiveModulesUnlocked;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyCompleted;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyLaunched;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationRecordArchived;
use Tangible\LMS\MegaTrace\Domain\Events\CredentialAttachedToJourney;
use Tangible\LMS\MegaTrace\Domain\Events\JourneyPlanRecorded;
use Tangible\LMS\MegaTrace\Domain\Events\LearningPathPersonalized;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\MegaTrace\Command\PublishFactCommand;

final class LaunchCertificationJourney extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly int $course_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CertificationJourneyLaunched($this->journey_id, $this->learner_id, $this->course_id);
    }
}

final class RecordJourneyPlan extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new JourneyPlanRecorded($this->journey_id, $this->learner_id);
    }
}

final class PersonalizeLearningPath extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly int $module_count,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new LearningPathPersonalized($this->journey_id, $this->learner_id, $this->module_count);
    }
}

final class UnlockAdaptiveModules extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly int $diagnostic_score,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new AdaptiveModulesUnlocked($this->journey_id, $this->learner_id, $this->diagnostic_score);
    }
}

final class CompleteCertificationJourney extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly int $final_score,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CertificationJourneyCompleted($this->journey_id, $this->learner_id, $this->final_score);
    }
}

final class AttachCredentialToJourney extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $credential_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CredentialAttachedToJourney($this->journey_id, $this->credential_id);
    }
}

final class ArchiveCertificationRecord extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $credential_id,
        public readonly string $receipt_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CertificationRecordArchived($this->journey_id, $this->credential_id, $this->receipt_id);
    }
}
