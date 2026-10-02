<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Runtime\Effects\IEffectCommand;

/**
 * E1: a handler-class effect (data only; an IExternalEffectHandler performs
 * it). Its provider is always down (send() throws), and its failure command
 * records itself, like PingEffect.
 */
final class PingHandledEffect implements IEffectCommand {

  public function __construct(public readonly int $n) {}

  public function idempotency_key(): string {
    return "ping-handled-effect:{$this->n}";
  }

  public function failure_command(\Throwable $last): ?ICommand {
    return new RecordingCommand("compensate-handled:{$this->n}");
  }

  public function send(): mixed {
    throw new \RuntimeException('provider down');
  }
}
