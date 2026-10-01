<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Codec;

/**
 * A stored LargeString cannot be decoded (D6 quarantine): wrong shape,
 * bad base64, length or sha256 mismatch, or over its cap.
 *
 * `quarantineReason` is the short text a host stores as `quarantine_reason`
 * on a process row (status `failed`, register 3.8 / R5), or that the
 * delivery ledger records for a fact (which then follows
 * IntegrationDelivery's poison path and its budget).
 */
final class UndecodableLargeString extends \UnexpectedValueException {

  public readonly string $quarantineReason;

  public function __construct(string $reason, ?\Throwable $previous = null) {
    $this->quarantineReason = $reason;
    parent::__construct('Undecodable LargeString: ' . $reason, 0, $previous);
  }
}
