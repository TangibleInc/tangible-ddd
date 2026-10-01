<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Runtime;

/**
 * Internal control flow of the relay: accept() matched 0 rows inside the
 * shared submit + accept transaction, so the transaction must roll back.
 *
 * @internal
 */
final class LeaseLostOnAccept extends \RuntimeException {

  public function __construct(public readonly string $eventId) {
    parent::__construct("lease lost on accept of $eventId");
  }
}
