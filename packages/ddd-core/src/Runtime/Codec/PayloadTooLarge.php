<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Codec;

/**
 * A value or fact payload is over its size cap (D6, register 3.8). Thrown
 * BEFORE commit: by LargeString's constructor, or by the outbox bus at
 * append (OutboxConfig::$max_payload_bytes), so the command fails and
 * nothing is staged.
 */
final class PayloadTooLarge extends \DomainException {

  public function __construct(
    public readonly string $subject,
    public readonly int $bytes,
    public readonly int $max_bytes,
  ) {
    parent::__construct(sprintf('%s is %d bytes, over the cap of %d bytes', $subject, $bytes, $max_bytes));
  }
}
