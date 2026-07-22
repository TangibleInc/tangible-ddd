<?php

declare(strict_types=1);

namespace TangibleDDD\MegaTrace\Scenario;

use Tangible\Cred\MegaTrace\Application\Commands\RecordSupervisorAttestation;
use Tangible\Cred\MegaTrace\Application\Commands\RunIssuanceRoutine;
use Tangible\Datastream\MegaTrace\Application\Commands\AcknowledgeRegistryReceipt;
use Tangible\LMS\MegaTrace\Application\Commands\LaunchCertificationJourney;
use Tangible\Quiz\MegaTrace\Application\Commands\SubmitCapstoneAttempt;
use Tangible\Quiz\MegaTrace\Application\Commands\SubmitDiagnosticAttempt;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Domain\Shared\Uuid;

final class ScenarioRuntime implements ScenarioLauncher
{
    public function launch(ScenarioRun $run): void
    {
        $this->dispatch(
            new LaunchCertificationJourney(
                $run->journey_id(),
                ScenarioIds::learner($run->journey_id()),
                ScenarioIds::course($run->journey_id()),
            ),
            $run->correlation_id,
        );
    }

    public function submit_diagnostic(string $scenario_id, string $journey_id): void
    {
        $this->dispatch(new SubmitDiagnosticAttempt(
            $journey_id,
            ScenarioIds::attempt($journey_id),
            82,
        ));
    }

    public function submit_capstone(string $scenario_id, string $journey_id): void
    {
        $this->dispatch(new SubmitCapstoneAttempt(
            $journey_id,
            ScenarioIds::attempt($journey_id),
            94,
        ));
    }

    public function record_attestation(string $scenario_id, string $journey_id): void
    {
        $this->dispatch(new RecordSupervisorAttestation(
            $journey_id,
            ScenarioIds::portfolio($journey_id),
            901,
        ));
    }

    public function acknowledge_registry(string $scenario_id, string $journey_id): void
    {
        $this->dispatch(new AcknowledgeRegistryReceipt(
            $journey_id,
            ScenarioIds::delivery($journey_id),
            ScenarioIds::receipt($journey_id),
        ));
    }

    public function continue_workflow(
        int $workflow_id,
        string $journey_id,
        int $learner_id,
        string $portfolio_id,
        string $correlation_id,
    ): void {
        $this->dispatch(
            new RunIssuanceRoutine(
                $journey_id,
                $learner_id,
                $portfolio_id,
                $workflow_id,
            ),
            $correlation_id,
        );
    }

    private function dispatch(ICommand $command, ?string $correlation_id = null): void
    {
        Correlation::within(
            new TraceContext($correlation_id ?? Uuid::v4()),
            static fn () => $command->send(),
        );
    }
}
