<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Ops;

/**
 * The layers of the one failure view (register 3.10, 5.1). Values are
 * persisted and shown; label() is the human name.
 */
enum Layer: string {
  case Relay = 'relay';
  case Delivery = 'delivery';
  case Wakeup = 'wakeup';
  case Process = 'process';
  case Workflow = 'workflow';
  case Effect = 'effect';
  case Transport = 'transport';

  public function label(): string {
    return match ($this) {
      self::Relay => 'Relay',
      self::Delivery => 'Delivery',
      self::Wakeup => 'Wakeup',
      self::Process => 'Process',
      self::Workflow => 'Workflow',
      self::Effect => 'Effect',
      self::Transport => 'Transport',
    };
  }

  /** What the layer retries and where exhaustion lands (register 5.1). */
  public function description(): string {
    return match ($this) {
      self::Relay => 'outbox row → transport submission; exhausted rows go to the relay DLQ',
      self::Delivery => 'one subscriber of one fact; exhausted subscribers are marked in the delivery ledger',
      self::Wakeup => 'a durable wakeup (continuation, timeout, retry) that keeps failing to run',
      self::Process => 'a process stranded mid-step (running, lock free, no live intent)',
      self::Workflow => 'a behaviour-workflow work item past its retries',
      self::Effect => 'an external effect performed but never recorded (charged but not written down)',
      self::Transport => 'a message the host transport itself failed (e.g. the Messenger failure transport)',
    };
  }
}
