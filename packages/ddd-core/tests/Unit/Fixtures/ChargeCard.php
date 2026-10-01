<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IExternalEffectCommand;

/** D1 fixture: an external effect whose send() always fails (the gateway is down). */
final class ChargeCard implements IExternalEffectCommand {

  public static int $sends = 0;

  public function __construct(public readonly int $order_id) {}

  public function send(): mixed {
    self::$sends++;
    throw new \RuntimeException("gateway down for order {$this->order_id}");
  }

  public function idempotency_key(): string {
    return "charge:{$this->order_id}";
  }

  public function perform(): EffectResult {
    return new EffectResult(['charged' => true]);
  }

  public function record(EffectResult $r): void {}

  public function failure_command(\Throwable $last): ?ICommand {
    return new RecordingCommand('charge-failed', ['order_id' => $this->order_id, 'error' => $last->getMessage()]);
  }
}
