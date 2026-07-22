<?php

declare(strict_types=1);

namespace Tangible\Datastream\MegaTrace\Application\Process;

use Tangible\Cred\MegaTrace\Domain\Events\CredentialIssued;
use Tangible\Datastream\MegaTrace\Application\Commands\CommitRegistryDelivery;
use Tangible\Datastream\MegaTrace\Application\Commands\PackageCredentialEvidence;
use Tangible\Datastream\MegaTrace\Domain\Events\RegistryReceiptReceived;
use TangibleDDD\Application\Process\AwaitEvent;
use TangibleDDD\Application\Process\Awaits;
use TangibleDDD\Application\Process\LongProcess;
use TangibleDDD\Application\Process\Result;
use TangibleDDD\Application\Process\StartsOn;
use TangibleDDD\Domain\Shared\DirectJsonLifecycleValue;
use TangibleDDD\MegaTrace\Scenario\ScenarioIds;

#[StartsOn(CredentialIssued::class)]
#[Awaits(RegistryReceiptReceived::class)]
final class EvidenceExportProcess extends LongProcess
{
    public function __construct(
        private readonly string $journey_id,
        private readonly string $portfolio_id,
        private readonly string $credential_id,
        private readonly string $stream_id,
        private readonly string $delivery_id,
    ) {
        parent::__construct(null);
    }

    public static function from_event(CredentialIssued $event): ?static
    {
        return new static(
            $event->journey_id,
            $event->portfolio_id,
            $event->credential_id,
            ScenarioIds::stream($event->journey_id),
            ScenarioIds::delivery($event->journey_id),
        );
    }

    protected function initialize(): Result
    {
        $state = new EvidenceExportState(
            $this->journey_id,
            $this->portfolio_id,
            $this->credential_id,
            $this->stream_id,
            $this->delivery_id,
        );

        return new Result(payload: $state, commands: [
            new PackageCredentialEvidence(
                $state->journey_id,
                $state->stream_id,
                $state->delivery_id,
                $state->credential_id,
            ),
        ]);
    }

    protected function wait_for_registry(EvidenceExportState $state): Result
    {
        return new Result(
            payload: $state,
            await: new AwaitEvent(RegistryReceiptReceived::class, ['journey_id' => $state->journey_id]),
        );
    }

    protected function commit_delivery(
        EvidenceExportState $state,
        RegistryReceiptReceived $receipt,
    ): Result {
        return new Result(commands: [
            new CommitRegistryDelivery(
                $state->journey_id,
                $state->portfolio_id,
                $state->credential_id,
                $state->delivery_id,
                $receipt->receipt_id,
            ),
        ]);
    }
}

final class EvidenceExportState extends DirectJsonLifecycleValue
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly string $credential_id,
        public readonly string $stream_id,
        public readonly string $delivery_id,
    ) {
        parent::__construct();
    }

    protected static function from_json_instance(\stdClass|array $rendered_data, ...$params): static
    {
        $data = (array) $rendered_data;
        return new static(
            (string) $data['journey_id'],
            (string) $data['portfolio_id'],
            (string) $data['credential_id'],
            (string) $data['stream_id'],
            (string) $data['delivery_id'],
        );
    }
}
