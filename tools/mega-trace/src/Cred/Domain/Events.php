<?php

declare(strict_types=1);

namespace Tangible\Cred\MegaTrace\Domain\Events;

use Tangible\Cred\MegaTrace\Domain\Aggregates\CredentialPortfolio;
use Tangible\Cred\MegaTrace\Domain\Aggregates\CredentialRecord;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;

#[Touches(Op::Created, CredentialPortfolio::class, id: 'portfolio_id')]
final class CompliancePortfolioOpened extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly string $portfolio_id,
    ) {
    }
}

#[Touches(Op::Updated, CredentialPortfolio::class, id: 'portfolio_id')]
final class ProvisionalCompetencyRecorded extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly int $score,
    ) {
    }
}

#[Touches(Op::Updated, CredentialPortfolio::class, id: 'portfolio_id')]
final class CredentialEvidenceVerified extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
    ) {
    }
}

#[Touches(Op::Updated, CredentialPortfolio::class, id: 'portfolio_id')]
final class IssuanceRoutineItemCompleted extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly string $behaviour,
        public readonly string $item_key,
    ) {
    }
}

#[Touches(Op::Updated, CredentialPortfolio::class, id: 'portfolio_id')]
final class SupervisorAttestationReceived extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly int $supervisor_id,
    ) {
    }
}

#[Touches(Op::Updated, CredentialPortfolio::class, id: 'portfolio_id')]
#[Touches(Op::Created, CredentialRecord::class, id: 'credential_id')]
final class CredentialIssued extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly string $portfolio_id,
        public readonly string $credential_id,
    ) {
    }
}

#[Touches(Op::Updated, CredentialRecord::class, id: 'credential_id')]
final class CredentialNotificationQueued extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $credential_id,
        public readonly string $channel,
    ) {
    }
}

#[Touches(Op::Updated, CredentialPortfolio::class, id: 'portfolio_id')]
final class PortfolioExported extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly string $receipt_id,
    ) {
    }
}
