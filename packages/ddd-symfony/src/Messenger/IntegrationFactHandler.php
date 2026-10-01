<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Runtime\Delivery\DeliveryOutcome;
use TangibleDDD\Runtime\Delivery\IntegrationDelivery;

/**
 * The Messenger handler for IntegrationFactMessage (register 3.5, X8):
 * unwraps the envelope and hands it to the core IntegrationDelivery, which
 * opens `Correlation::within(for_fact(event_id))` and runs the subscribers
 * in priority order (listeners 10, ignition 50, resume 99, any other int in
 * between), skipping those the ledger has already delivered.
 *
 * - Some subscriber still failed (under budget, or compensation pending):
 *   throws FactDeliveryIncomplete, so Messenger's `ddd_facts` retry
 *   strategy redelivers; configure it with the same budget (5 attempts,
 *   30 s x 2^n, capped at 1 h; the bundle prepends that).
 * - Every subscriber delivered or exhausted: returns the outcome and the
 *   message is acked.
 * - A message for another consumer, or a class that is missing or not an
 *   IIntegrationEvent: UnrecoverableMessageHandlingException (no retry,
 *   straight to the failure transport).
 *
 * Each subscriber's command commits in its own DBAL transaction through the
 * command bus. This handler must run on a bus WITHOUT doctrine_transaction.
 */
final class IntegrationFactHandler {

  public function __construct(
    private readonly IntegrationDelivery $delivery,
    private readonly string $consumer,
  ) {}

  public function __invoke(IntegrationFactMessage $fact): DeliveryOutcome {
    if ($fact->consumer !== $this->consumer) {
      throw new UnrecoverableMessageHandlingException(sprintf(
        'Fact %s belongs to consumer "%s"; this worker serves "%s".', $fact->event_id, $fact->consumer, $this->consumer
      ));
    }
    if (!class_exists($fact->event_class) || !is_a($fact->event_class, IIntegrationEvent::class, true)) {
      throw new UnrecoverableMessageHandlingException(sprintf(
        'Fact %s names class %s, which is missing or not an IIntegrationEvent.', $fact->event_id, $fact->event_class
      ));
    }

    $outcome = $this->delivery->deliver($fact->event_class, $fact->envelope);

    if ($outcome->needs_retry()) {
      throw new FactDeliveryIncomplete($fact, $outcome);
    }
    return $outcome;
  }
}
