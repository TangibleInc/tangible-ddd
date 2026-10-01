<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Effects;

/** One journal entry with its state (E2): what ITracksEffectState returns. */
final class EffectEntry {

  public function __construct(
    public readonly string $key,
    public readonly EffectResult $result,
    public readonly EffectState $state,
    public readonly \DateTimeImmutable $performed_at,
    public readonly ?\DateTimeImmutable $recorded_at = null,
  ) {}

  public function is_recorded(): bool {
    return $this->state === EffectState::Recorded;
  }
}
