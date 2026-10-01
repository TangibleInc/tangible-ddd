<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Support;

use TangibleDDD\Runtime\ITransactionBoundary;

/**
 * The mem host's answer to "is a transaction open on the connection this
 * store is used from?". It is the host boundary, except while
 * run_elsewhere() runs: then the caller acts as a second connection
 * on which no transaction is open (RelayRace's competitor runs inside the
 * relay's shared-connection transaction on mem, but stands for another
 * connection, whose claim() must not see the relay's open transaction).
 */
final class ConnectionView implements ITransactionBoundary {

  private int $elsewhere = 0;

  public function __construct(private readonly ITransactionBoundary $inner) {}

  public function run(callable $work): mixed {
    if ($this->elsewhere > 0) {
      throw new \LogicException('mem cannot open a transaction on the simulated second connection');
    }
    return $this->inner->run($work);
  }

  public function is_active(): bool {
    return $this->elsewhere === 0 && $this->inner->is_active();
  }

  /**
   * @template T
   * @param callable(): T $fn
   * @return T
   */
  public function run_elsewhere(callable $fn): mixed {
    $this->elsewhere++;
    try {
      return $fn();
    } finally {
      $this->elsewhere--;
    }
  }
}
