<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Scenario;

use Closure;
use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyCompleted;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentGraded;
use Tangible\Quiz\MegaTrace\Domain\Events\DiagnosticAssessmentPrepared;
use TangibleDDD\Application\Commands\ICommand;

use function TangibleDDD\WordPress\integration_listener;

/** Paces synthetic boundaries from facts proving the fleet is ready for them. */
final class ScenarioConductor
{
    private Closure $now;

    public function __construct(
        private readonly ScenarioQueue $queue,
        callable $now,
    ) {
        $this->now = Closure::fromCallable($now);
    }

    public function register(): void
    {
        integration_listener(DiagnosticAssessmentPrepared::class, [$this, 'assessment_prepared']);
        integration_listener(DiagnosticAssessmentGraded::class, [$this, 'assessment_graded']);
        integration_listener(CertificationJourneyCompleted::class, [$this, 'journey_completed']);
        integration_listener(CredentialIssued::class, [$this, 'credential_issued']);
    }

    public function assessment_prepared(DiagnosticAssessmentPrepared $event): ?ICommand
    {
        $this->schedule($event->journey_id, ScenarioPlan::SUBMIT_DIAGNOSTIC);
        return null;
    }

    public function assessment_graded(DiagnosticAssessmentGraded $event): ?ICommand
    {
        $this->schedule($event->journey_id, ScenarioPlan::SUBMIT_CAPSTONE);
        return null;
    }

    public function journey_completed(CertificationJourneyCompleted $event): ?ICommand
    {
        $this->schedule($event->journey_id, ScenarioPlan::RECORD_ATTESTATION);
        return null;
    }

    public function credential_issued(CredentialIssued $event): ?ICommand
    {
        $this->schedule($event->journey_id, ScenarioPlan::ACKNOWLEDGE_REGISTRY);
        return null;
    }

    private function schedule(string $journey_id, string $hook): void
    {
        $this->queue->schedule(
            ($this->now)() + ScenarioPlan::BOUNDARY_DELAY,
            $hook,
            ['scenario_id' => $journey_id, 'journey_id' => $journey_id],
            ScenarioCoordinator::GROUP,
        );
    }
}
