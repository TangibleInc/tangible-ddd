<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * Post-commit latency hint to the relay (D14). Called AFTER the command
 * commits; never inside the transaction on hosts without transactional
 * notify. sf implements it with transactional NOTIFY; elsewhere it is the
 * no-op NullRelayWakeup and polling covers delivery.
 *
 * Error behaviour: must not throw; a lost poke only costs latency.
 */
interface IRelayWakeup {
  public function poke(string $consumerPrefix): void;
}
