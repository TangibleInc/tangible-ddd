<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Delivery\ISubscriberProbe;

/** ISubscriberProbe answering from a fixed map; unknown actions are null. */
final class StaticSubscriberProbe implements ISubscriberProbe {

  /** @param array<string, bool> $answers integration action → has subscribers */
  public function __construct(private readonly array $answers = []) {}

  public function has_subscribers(string $integrationAction): ?bool {
    return $this->answers[$integrationAction] ?? null;
  }
}
