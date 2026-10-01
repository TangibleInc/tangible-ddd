<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Infra\IConsumerIdentity;

/**
 * An infrastructure signal (OutboxAttemptFailed, OutboxDeadLettered,
 * AuditSinkFailed, ProcessFailed, ...) as a Symfony event. Listen with
 * `#[AsEventListener(DddSignal::class)]`; `event` is the core signal and
 * `consumer` the consumer that emitted it.
 */
final class DddSignal {

  public function __construct(
    public readonly IInfrastructureEvent $event,
    public readonly IConsumerIdentity $consumer,
  ) {}

  /** The signal's action name, e.g. `outbox_dead_lettered`. */
  public function action(): string {
    return $this->event::action();
  }
}
