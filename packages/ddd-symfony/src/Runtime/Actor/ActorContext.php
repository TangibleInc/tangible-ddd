<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime\Actor;

use Symfony\Contracts\Service\ResetInterface;
use TangibleDDD\Runtime\Audit\Actor;

/**
 * An explicitly set actor that wins over every other source (D5): machine
 * authenticators (runner hosts, cron, ssh gateway, webhook providers) set an
 * ActorKind::Machine actor here. Reset between messages and requests
 * (kernel.reset), so it never leaks into the next unit of work.
 */
final class ActorContext implements ResetInterface {

  private ?Actor $actor = null;

  public function set(?Actor $actor): void {
    $this->actor = $actor;
  }

  public function get(): ?Actor {
    return $this->actor;
  }

  /**
   * @template T
   * @param callable(): T $work
   * @return T
   */
  public function run_as(Actor $actor, callable $work): mixed {
    $previous = $this->actor;
    $this->actor = $actor;
    try {
      return $work();
    } finally {
      $this->actor = $previous;
    }
  }

  public function reset(): void {
    $this->actor = null;
  }
}
