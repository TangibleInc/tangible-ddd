<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Fixtures\Codec;

use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/** The wrong way to carry binary: a plain string field (codec.large-payload refuses it at append). */
final class RawBlobAttached extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent {
  use IntegrationBehaviour;

  public function __construct(
    public readonly string $widget_id,
    public readonly string $body = '',
  ) {}

  public function payload(): array {
    return $this->integration_payload();
  }

  protected static function prefix(): string {
    return 'ddd_conformance';
  }
}
