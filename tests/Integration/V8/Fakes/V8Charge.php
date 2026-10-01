<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\V8\Fakes;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IExternalEffectCommand;

/**
 * D1 fixture: an external effect whose provider is down. send() counts and
 * throws; failure_command() is a V8ChargeFailed that records its sends.
 */
final class V8Charge implements IExternalEffectCommand {

  public static int $sends = 0;

  public function __construct(public readonly int $n) {}

  public function send(): mixed {
    self::$sends++;
    throw new \RuntimeException("provider down for charge {$this->n}");
  }

  public function idempotency_key(): string {
    return "v8-charge:{$this->n}";
  }

  public function perform(): EffectResult {
    return new EffectResult([]);
  }

  public function record(EffectResult $r): void {}

  public function failure_command(\Throwable $last): ?ICommand {
    return new V8ChargeFailed($this->n, $last->getMessage());
  }
}
