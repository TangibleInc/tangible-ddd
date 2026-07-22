<?php

declare(strict_types=1);

namespace Tangible\Quiz\MegaTrace\Application\IntegrationListeners;

use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\Quiz\MegaTrace\Application\Commands\SealAssessmentRecord;
use TangibleDDD\MegaTrace\Scenario\ScenarioIds;

use function TangibleDDD\WordPress\integration_listener;

final class FleetPolicies
{
    public function __construct()
    {
        integration_listener(
            CredentialIssued::class,
            static fn (CredentialIssued $event): SealAssessmentRecord => new SealAssessmentRecord(
                $event->journey_id,
                ScenarioIds::attempt($event->journey_id),
                $event->credential_id,
            ),
        );
    }
}
