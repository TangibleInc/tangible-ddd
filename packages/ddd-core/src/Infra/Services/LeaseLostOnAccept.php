<?php

declare(strict_types=1);

namespace TangibleDDD\Infra\Services;

/**
 * Thrown inside the relay's shared-connection transaction when accept()
 * matches 0 rows, so the boundary rolls the transport's submission back
 * with it (CR sfc-1). OutboxProcessor catches it outside the transaction
 * and reports the row as a lost lease; it never escapes process_batch().
 *
 * @internal
 */
final class LeaseLostOnAccept extends \RuntimeException {

  public function __construct(public readonly string $event_id) {
    parent::__construct("Lease lost on accept of $event_id; the shared submission rolls back");
  }
}
