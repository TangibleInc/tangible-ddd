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

  /**
   * RFC 4122 v5 (name-based, SHA-1): deterministic ids such as
   * uuid5(event_id, subscriber_id) and ignition keys (register 3.1, D13).
   * Delegates to the one implementation, Runtime\Ids\NameBasedUuid (CR-6).
   *
   * @throws \InvalidArgumentException when $namespace_uuid is not a UUID
   */
  public static function v5(string $namespace_uuid, string $name): string {
    return \TangibleDDD\Runtime\Ids\NameBasedUuid::v5($namespace_uuid, $name);
  }
}
