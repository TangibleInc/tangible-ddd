<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IExternalEffectCommand;

/** A D1 effect whose provider is always down (send() throws); its failure command records itself. */
final class PingEffect implements IExternalEffectCommand {

  public function __construct(public readonly int $n) {}

  public function idempotency_key(): string {
    return "ping-effect:{$this->n}";
  }

  public function perform(): EffectResult {
    throw new \RuntimeException('provider down');
  }

  public function record(EffectResult $r): void {}

  public function failure_command(\Throwable $last): ?ICommand {
    return new RecordingCommand("compensate:{$this->n}");
  }

  public function send(): mixed {
    throw new \RuntimeException('provider down');
  }
}
