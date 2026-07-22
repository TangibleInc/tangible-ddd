<?php

declare(strict_types=1);

namespace Tangible\Cred\MegaTrace\Application\Commands;

use Tangible\Cred\MegaTrace\Application\BehaviourWorkflows\IssuanceRoutine;
use Tangible\Cred\MegaTrace\Domain\Events\CompliancePortfolioOpened;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialEvidenceVerified;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialNotificationQueued;
use Tangible\Cred\MegaTrace\Domain\Events\PortfolioExported;
use Tangible\Cred\MegaTrace\Domain\Events\ProvisionalCompetencyRecorded;
use Tangible\Cred\MegaTrace\Domain\Events\SupervisorAttestationReceived;
use TangibleDDD\Application\Commands\ITransactionalCommand;
use TangibleDDD\Application\Commands\SelfHandlingCommand;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\MegaTrace\Command\PublishFactCommand;

final class OpenCompliancePortfolio extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly string $portfolio_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CompliancePortfolioOpened($this->journey_id, $this->learner_id, $this->portfolio_id);
    }
}

final class RecordProvisionalCompetency extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly int $score,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new ProvisionalCompetencyRecorded($this->journey_id, $this->portfolio_id, $this->score);
    }
}

final class VerifyCredentialEvidence extends PublishFactCommand
{
    protected const SYNTHETIC_WORK_MS = 1_150;

    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CredentialEvidenceVerified($this->journey_id, $this->portfolio_id);
    }
}

final class RunIssuanceRoutine extends SelfHandlingCommand implements ITransactionalCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly string $portfolio_id,
        public readonly ?int $workflow_id = null,
    ) {
    }

    protected function handle(IssuanceRoutine $routine): void
    {
        $routine->handle($this);
    }
}

final class RecordSupervisorAttestation extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly int $supervisor_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new SupervisorAttestationReceived(
            $this->journey_id,
            $this->portfolio_id,
            $this->supervisor_id,
        );
    }
}

final class IssueCredential extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly string $portfolio_id,
        public readonly string $credential_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CredentialIssued(
            $this->journey_id,
            $this->learner_id,
            $this->portfolio_id,
            $this->credential_id,
        );
    }
}

final class QueueCredentialNotification extends PublishFactCommand
{
    protected const SYNTHETIC_WORK_MS = 140;

    public function __construct(
        public readonly string $journey_id,
        public readonly string $credential_id,
        public readonly string $channel = 'email',
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CredentialNotificationQueued($this->journey_id, $this->credential_id, $this->channel);
    }
}

final class MarkPortfolioExported extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly string $receipt_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new PortfolioExported($this->journey_id, $this->portfolio_id, $this->receipt_id);
    }
}
