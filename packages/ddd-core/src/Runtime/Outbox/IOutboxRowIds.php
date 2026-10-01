<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Outbox;

/**
 * Integer outbox row ids → event ids (CR-SP-3). The shipped
 * RetryDeliveryCommand carries the integer `outbox_id` (register 3.4: "the
 * handler maps it to the row's event_id before calling retry()"), which only
 * stores with an integer key have. An IOutboxAdministration that has them
 * implements this as well; the wp one does.
 *
 * Error behaviour: null for an unknown id; storage errors throw.
 */
interface IOutboxRowIds {
  public function eventIdOf(int $outboxId): ?string;
}
