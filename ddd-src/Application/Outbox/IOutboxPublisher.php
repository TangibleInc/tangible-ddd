<?php

namespace TangibleDDD\Application\Outbox;


/**
 * Publishes an outbox entry to its next hop (Action Scheduler, external queue, etc).
 *
 * The outbox provides durability; the publisher provides transport.
 */
interface IOutboxPublisher {

  /**
   * @param OutboxEntry $entry Outbox entry (routing metadata + original payload)
   * @param array $wrapped_payload Payload with injected correlation context, suitable for transport
   */
  public function publish(OutboxEntry $entry, array $wrapped_payload): void;
}
