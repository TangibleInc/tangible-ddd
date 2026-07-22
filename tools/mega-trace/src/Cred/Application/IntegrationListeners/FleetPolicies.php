<?php

declare(strict_types=1);

namespace Tangible\Cred\MegaTrace\Application\IntegrationListeners;

use Tangible\Cred\MegaTrace\Application\Commands\MarkPortfolioExported;
use Tangible\Cred\MegaTrace\Application\Commands\OpenCompliancePortfolio;
use Tangible\Cred\MegaTrace\Application\Commands\QueueCredentialNotification;
use Tangible\Cred\MegaTrace\Application\Commands\RecordProvisionalCompetency;
use Tangible\Cred\MegaTrace\Application\Commands\RunIssuanceRoutine;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\Datastream\MegaTrace\Domain\Events\CredentialRegistrySynchronized;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyCompleted;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyLaunched;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentGraded;
use TangibleDDD\MegaTrace\Scenario\ScenarioIds;

use function TangibleDDD\WordPress\integration_listener;

final class FleetPolicies
{
    public function __construct()
    {
        integration_listener(
            CertificationJourneyLaunched::class,
            static fn (CertificationJourneyLaunched $event): OpenCompliancePortfolio => new OpenCompliancePortfolio(
                $event->journey_id,
                $event->learner_id,
                ScenarioIds::portfolio($event->journey_id),
            ),
        );
        integration_listener(
            DiagnosticAssessmentGraded::class,
            static fn (DiagnosticAssessmentGraded $event): RecordProvisionalCompetency => new RecordProvisionalCompetency(
                $event->journey_id,
                ScenarioIds::portfolio($event->journey_id),
                $event->score,
            ),
        );
        integration_listener(
            CertificationJourneyCompleted::class,
            static fn (CertificationJourneyCompleted $event): RunIssuanceRoutine => new RunIssuanceRoutine(
                $event->journey_id,
                $event->learner_id,
                ScenarioIds::portfolio($event->journey_id),
            ),
        );
        integration_listener(
            CredentialIssued::class,
            static fn (CredentialIssued $event): QueueCredentialNotification => new QueueCredentialNotification(
                $event->journey_id,
                $event->credential_id,
            ),
        );
        integration_listener(
            CredentialRegistrySynchronized::class,
            static fn (CredentialRegistrySynchronized $event): MarkPortfolioExported => new MarkPortfolioExported(
                $event->journey_id,
                $event->portfolio_id,
                $event->receipt_id,
            ),
        );
    }
}
