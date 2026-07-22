<?php

declare(strict_types=1);

namespace Tangible\Datastream\MegaTrace\Application\IntegrationListeners;

use Tangible\Datastream\MegaTrace\Application\Commands\CaptureEvidenceSnapshot;
use Tangible\Datastream\MegaTrace\Application\Commands\OpenEvidenceStream;
use Tangible\Datastream\MegaTrace\Application\Commands\PrepareCredentialExport;
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
            static fn (CertificationJourneyLaunched $event): OpenEvidenceStream => new OpenEvidenceStream(
                $event->journey_id,
                ScenarioIds::stream($event->journey_id),
                $event->learner_id,
            ),
        );
        integration_listener(
            DiagnosticAssessmentGraded::class,
            static fn (DiagnosticAssessmentGraded $event): CaptureEvidenceSnapshot => new CaptureEvidenceSnapshot(
                $event->journey_id,
                ScenarioIds::stream($event->journey_id),
                $event->score,
            ),
        );
        integration_listener(
            CertificationJourneyCompleted::class,
            static fn (CertificationJourneyCompleted $event): PrepareCredentialExport => new PrepareCredentialExport(
                $event->journey_id,
                ScenarioIds::stream($event->journey_id),
                $event->final_score,
            ),
        );
    }
}
