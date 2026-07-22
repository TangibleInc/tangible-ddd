<?php

declare(strict_types=1);

namespace Tangible\Datastream\MegaTrace\Domain\Events;

use Tangible\Datastream\MegaTrace\Domain\Aggregates\EvidenceStream;
use Tangible\Datastream\MegaTrace\Domain\Aggregates\RegistryDelivery;
use TangibleDDD\Domain\Events\IntegrationEvent;
use TangibleDDD\Domain\Events\Op;
use TangibleDDD\Domain\Events\Touches;

#[Touches(Op::Created, EvidenceStream::class, id: 'stream_id')]
final class EvidenceStreamOpened extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $stream_id,
        public readonly int $learner_id,
    ) {
    }
}

#[Touches(Op::Updated, EvidenceStream::class, id: 'stream_id')]
final class EvidenceSnapshotCaptured extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $stream_id,
        public readonly int $score,
    ) {
    }
}

#[Touches(Op::Updated, EvidenceStream::class, id: 'stream_id')]
final class CredentialExportPrepared extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $stream_id,
        public readonly int $final_score,
    ) {
    }
}

#[Touches(Op::Updated, EvidenceStream::class, id: 'stream_id')]
#[Touches(Op::Created, RegistryDelivery::class, id: 'delivery_id')]
final class EvidencePackageCreated extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $stream_id,
        public readonly string $delivery_id,
        public readonly string $credential_id,
    ) {
    }
}

#[Touches(Op::Updated, RegistryDelivery::class, id: 'delivery_id')]
final class RegistryReceiptReceived extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $delivery_id,
        public readonly string $receipt_id,
    ) {
    }
}

#[Touches(Op::Updated, RegistryDelivery::class, id: 'delivery_id')]
final class CredentialRegistrySynchronized extends IntegrationEvent
{
    public function __construct(
        public readonly string $journey_id,
        public readonly string $portfolio_id,
        public readonly string $credential_id,
        public readonly string $delivery_id,
        public readonly string $receipt_id,
    ) {
    }
}
