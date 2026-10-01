<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Wakeup;

/**
 * How an idle relay loop waits for more work (D14): return early when
 * poked, else after $seconds (the poll fallback). sf-local.
 */
interface IRelayWaiter {

  /** @return bool true when woken by a poke, false on timeout */
  public function wait(float $seconds): bool;
}
