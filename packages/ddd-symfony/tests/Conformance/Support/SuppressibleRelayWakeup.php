<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance\Support;

use TangibleDDD\Runtime\Delivery\IRelayWakeup;

/**
 * The bundle's relay wakeup (PostgresNotifyRelayWakeup, a transactional
 * NOTIFY) with a one-shot "lost NOTIFY" fault for `wakeup.post-commit`.
 */
final class SuppressibleRelayWakeup implements IRelayWakeup {

  private bool $suppressNext = false;

  public function __construct(private readonly IRelayWakeup $inner) {}

  public function suppressNext(): void {
    $this->suppressNext = true;
  }

  public function poke(string $consumerPrefix): void {
    if ($this->suppressNext) {
      $this->suppressNext = false;
      return;
    }
    $this->inner->poke($consumerPrefix);
  }
}
