<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures\Effects\CommandHandlers;

use TangibleDDD\Core\Tests\Unit\Fixtures\Effects\Commands\EnsureCustomerCommand;
use TangibleDDD\Core\Tests\Unit\Fixtures\Effects\FakeStripe;
use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\Effects\IEffectCommand;
use TangibleDDD\Runtime\Effects\IExternalEffectHandler;
use TangibleDDD\Runtime\ITransactionBoundary;

/**
 * E1 fixture: the effect handler, located by the naming convention
 * (Commands\EnsureCustomerCommand → CommandHandlers\EnsureCustomerHandler)
 * and built with its collaborators injected.
 *
 * @implements IExternalEffectHandler<EnsureCustomerCommand>
 */
final class EnsureCustomerHandler implements IExternalEffectHandler {

  /** @var list<array{account: string, ref: ?string, in_tx: bool}> */
  public array $recorded = [];

  public int $record_failures_left = 0;

  public function __construct(
    private readonly FakeStripe $stripe,
    private readonly ITransactionBoundary $tx,
  ) {}

  public function perform(IEffectCommand $command): EffectResult {
    \assert($command instanceof EnsureCustomerCommand);
    return $this->stripe->create_customer($command->account_id, $command->idempotency_key());
  }

  public function record(IEffectCommand $command, EffectResult $result): void {
    \assert($command instanceof EnsureCustomerCommand);
    if ($this->record_failures_left > 0) {
      $this->record_failures_left--;
      throw new \RuntimeException('account save failed');
    }
    $this->recorded[] = ['account' => $command->account_id, 'ref' => $result->external_ref, 'in_tx' => $this->tx->is_active()];
  }
}
