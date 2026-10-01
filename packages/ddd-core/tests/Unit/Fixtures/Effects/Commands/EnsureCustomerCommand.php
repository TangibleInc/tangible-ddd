<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures\Effects\Commands;

use League\Tactician\CommandBus;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Runtime\Effects\IEffectCommand;

/**
 * E1 fixture (TXP EnsureStripeCustomer): the command carries data only; its
 * effect is performed and recorded by CommandHandlers\EnsureCustomerHandler.
 */
final class EnsureCustomerCommand implements IEffectCommand {

  public static ?CommandBus $bus = null;

  public function __construct(public readonly string $account_id) {}

  public function send(): mixed {
    return self::$bus?->handle($this);
  }

  public function idempotency_key(): string {
    return "stripe-customer:{$this->account_id}";
  }

  public function failure_command(\Throwable $last): ?ICommand {
    return new CustomerCreationFailed($this->account_id, $last->getMessage());
  }
}
