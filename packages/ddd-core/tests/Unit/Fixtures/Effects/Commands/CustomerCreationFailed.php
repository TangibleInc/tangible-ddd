<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures\Effects\Commands;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;

/** The failure command of EnsureCustomerCommand (E1 fixture). */
final class CustomerCreationFailed implements ICommand, ITransactionalCommand {

  /** @var list<self> */
  public static array $handled = [];

  public function __construct(public readonly string $account_id, public readonly string $error) {}

  public function send(): mixed {
    return EnsureCustomerCommand::$bus?->handle($this);
  }
}
