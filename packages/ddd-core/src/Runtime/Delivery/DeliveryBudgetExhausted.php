<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Delivery;

/**
 * The `$last` handed to a RE-FIRED onExhausted callback, when the original
 * handler throwable is gone (the previous process crashed, or the callback
 * threw, before the terminal ledger marker was written). Its message is the
 * ledger's lastError(), so a callback that reads `$last->getMessage()` sees
 * the same text on the first firing and on a re-fire.
 */
final class DeliveryBudgetExhausted extends \RuntimeException {

  public function __construct(
    public readonly string $subscriberId,
    public readonly string $eventId,
    public readonly int $attempts,
    ?string $lastError,
  ) {
    parent::__construct($lastError ?? sprintf(
      'Subscriber %s exhausted its delivery budget for event %s after %d attempts',
      $subscriberId, $eventId, $attempts
    ));
  }
}
