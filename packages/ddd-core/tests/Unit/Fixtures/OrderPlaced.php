<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Fixtures;

use TangibleDDD\Domain\Events\IntegrationEvent;

/** A plain integration fact; implements a marker so marker subscription can be tested. */
final class OrderPlaced extends IntegrationEvent implements BillingFact {

  public function __construct(
    public readonly int $order_id = 1,
    public readonly string $sku = 'sku-1',
  ) {}

  protected static function prefix(): string {
    return 'acme';
  }
}
