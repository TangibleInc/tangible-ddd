<?php

declare(strict_types=1);

namespace Tangible\Datastream\MegaTrace\Application\Commands;

use Tangible\Datastream\MegaTrace\Domain\Events\CredentialExportPrepared;
use Tangible\Datastream\MegaTrace\Domain\Events\CredentialRegistrySynchronized;
use Tangible\Datastream\MegaTrace\Domain\Events\EvidencePackageCreated;
use Tangible\Datastream\MegaTrace\Domain\Events\EvidenceSnapshotCaptured;
use Tangible\Datastream\MegaTrace\Domain\Events\EvidenceStreamOpened;
use Tangible\Datastream\MegaTrace\Domain\Events\RegistryReceiptReceived;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\MegaTrace\Command\PublishFactCommand;

final class OpenEvidenceStream extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $stream_id,
        public readonly int $learner_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new EvidenceStreamOpened($this->journey_id, $this->stream_id, $this->learner_id);
    }
}

final class CaptureEvidenceSnapshot extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $stream_id,
        public readonly int $score,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new EvidenceSnapshotCaptured($this->journey_id, $this->stream_id, $this->score);
    }
}

final class PrepareCredentialExport extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $stream_id,
        public readonly int $final_score,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CredentialExportPrepared($this->journey_id, $this->stream_id, $this->final_score);
    }
}

final class PackageCredentialEvidence extends PublishFactCommand
{
    protected const SYNTHETIC_WORK_MS = 820;

    public function __construct(
        public readonly string $journey_id,
        public readonly string $stream_id,
        public readonly string $delivery_id,
        public readonly string $credential_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new EvidencePackageCreated(
            $this->journey_id,
            $this->stream_id,
            $this->delivery_id,
            $this->credential_id,
        );
    }
}

final class AcknowledgeRegistryReceipt extends PublishFactCommand
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $delivery_id,
        public readonly string $receipt_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new RegistryReceiptReceived($this->journey_id, $this->delivery_id, $this->receipt_id);
    }
}

final class CommitRegistryDelivery extends PublishFactCommand
{
    protected const SYNTHETIC_WORK_MS = 360;

    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly string $credential_id,
        public readonly string $delivery_id,
        public readonly string $receipt_id,
    ) {
    }

    protected function fact(): IIntegrationEvent
    {
        return new CredentialRegistrySynchronized(
            $this->journey_id,
            $this->portfolio_id,
            $this->credential_id,
            $this->delivery_id,
            $this->receipt_id,
        );
    }
}
