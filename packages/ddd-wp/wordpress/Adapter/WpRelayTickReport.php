<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Infra\Services\ProcessingResult;

/** What one WpRelayTick::run() did, per step (null = the step did not run). */
final class WpRelayTickReport {

  /** @param array<string, string> $errors step => message */
  public function __construct(
    public readonly string $prefix,
    public readonly bool $portForm,
    public readonly ?ProcessingResult $relay,
    public readonly ?int $reprojected,
    public readonly ?WpStrandedReport $stranded,
    public readonly array $errors,
  ) {}

  public function ok(): bool {
    return $this->errors === [];
  }

  /** One log/CLI line. */
  public function summary(): string {
    $parts = [sprintf('[%s] relay (%s form): ', $this->prefix, $this->portForm ? 'port' : '0.6')];
    $parts[] = $this->relay === null
      ? 'failed'
      : sprintf('%d processed, %d completed, %d failed, %d moved to DLQ', $this->relay->total, $this->relay->completed, $this->relay->failed, $this->relay->dlq);
    if ($this->reprojected !== null) {
      $parts[] = sprintf('; %d wakeups re-projected', $this->reprojected);
    }
    if ($this->stranded !== null) {
      $parts[] = sprintf(
        '; stranded: %d continued, %d already queued, %d running (operator view)',
        count($this->stranded->minted), count($this->stranded->alreadyQueued), count($this->stranded->running)
      );
    }
    foreach ($this->errors as $step => $message) {
      $parts[] = "; $step error: $message";
    }
    return implode('', $parts);
  }
}
