<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use TangibleDDD\Runtime\Delivery\DeliveryOutcome;

/**
 * Thrown by the delivery handler while some subscriber of the fact is still
 * under budget (or compensation-pending), so Messenger's retry strategy on
 * `ddd_facts` redelivers the message; the retry runs only the undelivered
 * subscribers (the ledger skips the rest). Retryable by design.
 */
final class FactDeliveryIncomplete extends \RuntimeException {

  public function __construct(public readonly IntegrationFactMessage $fact, public readonly DeliveryOutcome $outcome) {
    parent::__construct(sprintf(
      'Fact %s (%s) not fully delivered; failed subscribers: %s',
      $fact->eventId, $fact->eventClass, implode(', ', $outcome->failed)
    ));
  }
}
