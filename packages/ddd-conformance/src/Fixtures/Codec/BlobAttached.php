<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Codec;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;
use TangibleDDD\Runtime\Codec\LargeString;

/**
 * A self-publishing fact carrying a large binary scalar (D6,
 * codec.large-payload; TXP's RunnerJobOrdered.payload_json). The
 * LargeString constructor parameter is encoded by IntegrationBehaviour
 * and revived by type on delivery.
 */
final class BlobAttached extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(
    public readonly string $widget_id,
    public readonly ?LargeString $blob = null,
  ) {}

  public function payload(): array {
    return $this->integration_payload();
  }

  protected static function prefix(): string {
    return 'ddd_conformance';
  }
}
