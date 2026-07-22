<?php

declare(strict_types=1);

namespace Tangible\LMS\MegaTrace\Application\IntegrationListeners;

use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\Datastream\MegaTrace\Domain\Events\CredentialRegistrySynchronized;
use Tangible\LMS\MegaTrace\Application\Commands\ArchiveCertificationRecord;
use Tangible\LMS\MegaTrace\Application\Commands\AttachCredentialToJourney;
use Tangible\LMS\MegaTrace\Application\Commands\UnlockAdaptiveModules;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentGraded;

use function TangibleDDD\WordPress\integration_listener;

final class FleetPolicies
{
    public function __construct()
    {
        integration_listener(
            DiagnosticAssessmentGraded::class,
            static fn (DiagnosticAssessmentGraded $event): UnlockAdaptiveModules => new UnlockAdaptiveModules(
                $event->journey_id,
                $event->learner_id,
                $event->score,
            ),
        );
        integration_listener(
            CredentialIssued::class,
            static fn (CredentialIssued $event): AttachCredentialToJourney => new AttachCredentialToJourney(
                $event->journey_id,
                $event->credential_id,
            ),
        );
        integration_listener(
            CredentialRegistrySynchronized::class,
            static fn (CredentialRegistrySynchronized $event): ArchiveCertificationRecord => new ArchiveCertificationRecord(
                $event->journey_id,
                $event->credential_id,
                $event->receipt_id,
            ),
        );
    }
}
