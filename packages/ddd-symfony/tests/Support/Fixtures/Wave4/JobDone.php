<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures\Wave4;

use TangibleDDD\Application\Process\IAwaitKeyed;
use TangibleDDD\Domain\Events\IntegrationEvent;

/** D3: a job report keyed on the job id the process minted (LongProcess::step_ref()). */
final class JobDone extends IntegrationEvent implements IAwaitKeyed {

  public function __construct(public readonly string $job_id = '', public readonly bool $ok = true) {}

  public function await_key(): ?string {
    return $this->job_id === '' ? null : $this->job_id;
  }

  protected static function prefix(): string {
    return 'sft';
  }
}
