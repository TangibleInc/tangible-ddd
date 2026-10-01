<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Events;

use TangibleDDD\Application\Process\IAwaitKeyed;
use TangibleDDD\Domain\Events\DomainEvent;
use TangibleDDD\Domain\Events\IAnnouncesIntegration;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Domain\Events\IntegrationBehaviour;

/** Reference scenario: the job report, keyed on the job id the saga minted (D3). */
final class ToyJobFinished extends DomainEvent implements IAnnouncesIntegration, IIntegrationEvent, IAwaitKeyed {
  use IntegrationBehaviour;

  public function __construct(public readonly string $job_id, public readonly bool $ok = true) {}

  public function await_key(): ?string {
    return $this->job_id === '' ? null : $this->job_id;
  }

  public function payload(): array {
    return $this->integration_payload();
  }
}
