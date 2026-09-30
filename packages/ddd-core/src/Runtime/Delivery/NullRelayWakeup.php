<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/** Default IRelayWakeup: polling only. */
final class NullRelayWakeup implements IRelayWakeup {

  public function poke(string $consumerPrefix): void {}
}
