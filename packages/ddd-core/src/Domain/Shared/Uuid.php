<?php

namespace TangibleDDD\Domain\Shared;

/**
 * RFC 4122 v4, CSPRNG-backed (random_int), deliberately NO fallback:
 * random_int throws only when the OS entropy source is unavailable, and a
 * box in that state should fail loudly, not mint degraded identifiers.
 * The framework's one UUID mint — correlation ids, outbox event ids, and
 * anything else that needs coordination-free identity.
 */
final class Uuid {

  public static function v4(): string {
    return sprintf(
      '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
      random_int(0, 0xffff), random_int(0, 0xffff),
      random_int(0, 0xffff),
      random_int(0, 0x0fff) | 0x4000,
      random_int(0, 0x3fff) | 0x8000,
      random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
    );
  }
}
