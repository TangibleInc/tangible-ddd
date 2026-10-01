<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures\Effects;

use TangibleDDD\Runtime\Effects\EffectResult;
use TangibleDDD\Runtime\ITransactionBoundary;

/** The provider client an effect handler gets injected (E1 fixture). */
final class FakeStripe {

  /** @var list<array{account: string, key: string, in_tx: bool}> */
  public array $calls = [];

  public ?\Throwable $fails = null;

  public function __construct(private readonly ITransactionBoundary $tx) {}

  public function create_customer(string $account, string $idempotency_key): EffectResult {
    $this->calls[] = ['account' => $account, 'key' => $idempotency_key, 'in_tx' => $this->tx->is_active()];
    if ($this->fails !== null) {
      throw $this->fails;
    }
    return new EffectResult(['customer' => 'cus_' . count($this->calls)], 'cus_' . count($this->calls));
  }
}
