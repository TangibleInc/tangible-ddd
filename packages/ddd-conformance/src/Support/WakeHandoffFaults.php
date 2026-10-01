<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Support;

use TangibleDDD\Runtime\Scheduling\IWakeHandler;
use TangibleDDD\Runtime\Scheduling\WakeupIntent;

/**
 * For hosts whose drain executes claimed intents itself (pdo jobs, mem):
 * the "wake transport" is the hand-off from the drain to the wake handler.
 * failNext() makes the next hand-off fail before the handler runs, as an
 * unavailable queue would; Drain then retryLater()s the claimed intent and
 * the intent row survives (process.intent-survives-queue-failure).
 *
 * One instance is shared by every worker of a fixture; wrap() gives each
 * worker's drain its handler behind the shared fault.
 */
final class WakeHandoffFaults {

  /** @var list<string> */
  private array $pending = [];

  public function failNext(string $reason): void {
    $this->pending[] = $reason;
  }

  public function wrap(IWakeHandler $handler): IWakeHandler {
    return new class($this, $handler) implements IWakeHandler {
      public function __construct(private readonly WakeHandoffFaults $faults, private readonly IWakeHandler $inner) {}

      public function wake(WakeupIntent $intent): void {
        $this->faults->throwIfArmed($intent);
        $this->inner->wake($intent);
      }
    };
  }

  /** @internal */
  public function throwIfArmed(WakeupIntent $intent): void {
    if ($this->pending !== []) {
      $reason = array_shift($this->pending);
      throw new \RuntimeException("wake transport unavailable for {$intent->idempotencyKey}: $reason");
    }
  }
}
