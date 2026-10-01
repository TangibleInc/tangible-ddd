<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Ops;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\ListableReceiverInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use TangibleDDD\Runtime\Ops\IOperatorItemSource;
use TangibleDDD\Runtime\Ops\Layer;
use TangibleDDD\Runtime\Ops\OperatorItem;
use TangibleDDD\Symfony\Messenger\IntegrationFactMessage;
use TangibleDDD\Symfony\Messenger\ProcessWakeupMessage;

/**
 * The Messenger failure transport (`tangible_ddd.messenger.failure_transport`,
 * default `ddd_failed`) as layer `transport` of the operator view (D9,
 * register 3.10, 5.1).
 *
 * A fact message lands there when its handler budget (the Messenger retry
 * strategy = the delivery budget) ran out; the delivery ledger stays the
 * budget's source of truth, so items carry no budget of their own. Item:
 *
 * - key: the transport message id (what `messenger:failed:show|retry|remove`
 *   take);
 * - attempts: the highest Messenger retry count plus the first attempt;
 * - last error: the message (fact event id, or wakeup intent key) and the
 *   last ErrorDetailsStamp;
 * - first seen: the first redelivery, null when it never retried;
 * - repairs: Messenger's own `messenger:failed:retry|remove <transport>`.
 *
 * Messages of another consumer (IntegrationFactMessage / ProcessWakeupMessage
 * with a different prefix) are left out. A receiver that cannot list
 * (not ListableReceiverInterface) or no failure transport contributes
 * nothing. Storage errors propagate.
 */
final class MessengerFailureTransportSource implements IOperatorItemSource {

  public function __construct(
    private readonly ?ReceiverInterface $failureTransport,
    private readonly string $consumer,
    private readonly string $transportName,
  ) {}

  public function items(?Layer $layer, int $limit): array {
    if ($limit <= 0 || ($layer !== null && $layer !== Layer::Transport) || !$this->failureTransport instanceof ListableReceiverInterface) {
      return [];
    }
    $items = [];
    foreach ($this->failureTransport->all($limit) as $envelope) {
      $item = $this->item($envelope);
      if ($item !== null) {
        $items[] = $item;
      }
      if (count($items) >= $limit) {
        break;
      }
    }
    return $items;
  }

  private function item(Envelope $envelope): ?OperatorItem {
    $message = $envelope->getMessage();
    $owner = ($message instanceof IntegrationFactMessage || $message instanceof ProcessWakeupMessage) ? $message->consumer : null;
    if ($owner !== null && $owner !== $this->consumer) {
      return null;
    }

    $retries = 0;
    $firstSeen = null;
    foreach ($envelope->all(RedeliveryStamp::class) as $stamp) {
      /** @var RedeliveryStamp $stamp */
      $retries = max($retries, $stamp->getRetryCount());
      if ($stamp->getRetryCount() > 0 && ($firstSeen === null || $stamp->getRedeliveredAt() < $firstSeen)) {
        $firstSeen = $stamp->getRedeliveredAt();
      }
    }
    $error = $envelope->last(ErrorDetailsStamp::class);
    $what = match (true) {
      $message instanceof IntegrationFactMessage => "fact {$message->event_type} {$message->event_id}",
      $message instanceof ProcessWakeupMessage => "wakeup {$message->key}",
      default => get_class($message),
    };

    return new OperatorItem(
      Layer::Transport,
      $this->consumer,
      (string) ($envelope->last(TransportMessageIdStamp::class)?->getId() ?? '?'),
      $retries + 1,
      null,
      $error instanceof ErrorDetailsStamp
        ? sprintf('%s: %s: %s', $what, $error->getExceptionClass(), $error->getExceptionMessage())
        : $what,
      $firstSeen,
      ["messenger:failed:retry {$this->transportName}", "messenger:failed:remove {$this->transportName}"],
    );
  }
}
