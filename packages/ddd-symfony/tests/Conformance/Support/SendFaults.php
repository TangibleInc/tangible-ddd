<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

/** One-shot send failures shared by several FaultInjectingSenders (one per worker). */
final class SendFaults {

  /** @var list<string> */
  private array $pending = [];

  public function failNext(string $reason): void {
    $this->pending[] = $reason;
  }

  public function take(): ?string {
    return array_shift($this->pending);
  }
}
