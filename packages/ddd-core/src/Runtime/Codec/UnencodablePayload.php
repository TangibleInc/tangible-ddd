<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Codec;

/**
 * A fact payload cannot be stored as JSON (D6), typically a binary string
 * in a plain `string` field. Thrown by the outbox bus at append, before
 * commit, instead of letting the store fail later or store a broken row.
 * Wrap such a field in LargeString.
 */
final class UnencodablePayload extends \DomainException {

  public function __construct(string $eventName, string $jsonError) {
    parent::__construct(sprintf(
      'The payload of %s cannot be encoded as JSON (%s); carry binary or non-UTF-8 strings as a LargeString field.',
      $eventName, $jsonError
    ));
  }
}
