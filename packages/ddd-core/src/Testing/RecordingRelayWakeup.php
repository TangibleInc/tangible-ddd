<?php

declare(strict_types=1);

namespace TangibleDDD\Testing;

use TangibleDDD\Runtime\Delivery\IRelayWakeup;

/** Records pokes. */
final class RecordingRelayWakeup implements IRelayWakeup {

  /** @var list<string> */
  public array $pokes = [];

  public function poke(string $consumerPrefix): void {
    $this->pokes[] = $consumerPrefix;
  }
}
