<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Kernel\App\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\CQRS\CommandBusAware;
use TangibleDDD\Runtime\Effects\IEffectCommand;

/**
 * E1 (wave 5): a handler-class effect, the effect as data. RefundToyHandler
 * (an autoconfigured IExternalEffectHandler) performs and records it.
 */
final class RefundToyCommand implements IEffectCommand {
  use CommandBusAware;

  public function __construct(public readonly string $charge_id, public readonly int $amount) {}

  public function idempotency_key(): string {
    return "toy-refund:{$this->charge_id}";
  }

  public function failure_command(\Throwable $last): ?ICommand {
    return null;
  }
}
