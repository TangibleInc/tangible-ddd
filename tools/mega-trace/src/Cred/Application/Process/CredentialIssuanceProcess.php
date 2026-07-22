<?php

declare(strict_types=1);

namespace Tangible\Cred\MegaTrace\Application\Process;

use Tangible\Cred\MegaTrace\Application\Commands\IssueCredential;
use Tangible\Cred\MegaTrace\Application\Commands\VerifyCredentialEvidence;
use Tangible\Cred\MegaTrace\Domain\Events\SupervisorAttestationReceived;
use Tangible\LMS\MegaTrace\Domain\Events\CertificationJourneyCompleted;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;
use TangibleDDD\MegaTrace\Scenario\ScenarioIds;

#[StartsOn(CertificationJourneyCompleted::class)]
#[Awaits(SupervisorAttestationReceived::class)]
final class CredentialIssuanceProcess extends LongProcess
{
    public function __construct(
        private readonly string $journey_id,
        private readonly int $learner_id,
        private readonly string $portfolio_id,
        private readonly string $credential_id,
    ) {
        parent::__construct(null);
    }

    public static function from_event(CertificationJourneyCompleted $event): ?static
    {
        return new static(
            $event->journey_id,
            $event->learner_id,
            ScenarioIds::portfolio($event->journey_id),
            ScenarioIds::credential($event->journey_id),
        );
    }

    protected function initialize(): Result
    {
        $state = new CredentialIssuanceState(
            $this->journey_id,
            $this->learner_id,
            $this->portfolio_id,
            $this->credential_id,
        );

        return new Result(payload: $state, commands: [
            new VerifyCredentialEvidence($state->journey_id, $state->portfolio_id),
        ]);
    }

    protected function wait_for_attestation(CredentialIssuanceState $state): Result
    {
        return new Result(
            payload: $state,
            await: new AwaitEvent(SupervisorAttestationReceived::class, ['journey_id' => $state->journey_id]),
        );
    }

    protected function issue_credential(
        CredentialIssuanceState $state,
        SupervisorAttestationReceived $attestation,
    ): Result {
        return new Result(commands: [
            new IssueCredential(
                $state->journey_id,
                $state->learner_id,
                $state->portfolio_id,
                $state->credential_id,
            ),
        ]);
    }
}

final class CredentialIssuanceState extends DirectJsonLifecycleValue
{
    public function __construct(
        public readonly string $journey_id,
        public readonly int $learner_id,
        public readonly string $portfolio_id,
        public readonly string $credential_id,
    ) {
        parent::__construct();
    }

    protected static function from_json_instance(\stdClass|array $rendered_data, ...$params): static
    {
        $data = (array) $rendered_data;
        return new static(
            (string) $data['journey_id'],
            (int) $data['learner_id'],
            (string) $data['portfolio_id'],
            (string) $data['credential_id'],
        );
    }
}
