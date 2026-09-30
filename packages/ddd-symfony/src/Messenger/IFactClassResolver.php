<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Messenger;

use TangibleDDD\Runtime\Outbox\Claim;

/**
 * The PHP class of a claimed fact, which the outbox record does not carry
 * (CR sf-1). null = unknown; the transport then rejects the submission, so
 * the row retries and ends in the relay DLQ instead of vanishing.
 */
interface IFactClassResolver {
  public function classFor(Claim $claim): ?string;
}
