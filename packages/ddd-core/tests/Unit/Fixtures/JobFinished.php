<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Process\IAwaitKeyed;
use TangibleDDD\Domain\Events\IntegrationEvent;

/** A runner job report, keyed on the job id the process minted (D3). */
final class JobFinished extends IntegrationEvent implements IAwaitKeyed {

  public function __construct(
    public readonly string $job_id = '',
    public readonly bool $ok = true,
  ) {}

  public function await_key(): ?string {
    return $this->job_id === '' ? null : $this->job_id;
  }

  protected static function prefix(): string {
    return 'acme';
  }
}
