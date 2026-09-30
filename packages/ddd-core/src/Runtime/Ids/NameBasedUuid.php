<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Ids;

/**
 * RFC 4122 version-5 (SHA-1, name-based) UUIDs: the deterministic id mint of
 * register 3.1 / D13.
 *
 * The register places this as `TangibleDDD\Domain\Shared\Uuid::v5()`. Wave 1
 * may not edit existing classes, so the algorithm lives here and `Uuid::v5()`
 * delegates to it once the class moves in wave 2 (CR-6 in
 * Runtime/API-CHANGE-REQUESTS.md).
 *
 * Uses: ignition keys `uuid5(event_id, process_class)` (X7), deterministic
 * command ids `uuid5(event_id, subscriber_id)` inside a fact cause (3.8).
 */
final class NameBasedUuid {

  /**
   * @param string $namespace_uuid a canonical UUID (any case, hyphenated)
   * @throws \InvalidArgumentException when $namespace_uuid is not a UUID
   */
  public static function v5(string $namespace_uuid, string $name): string {
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $namespace_uuid)) {
      throw new \InvalidArgumentException("uuid5 namespace '$namespace_uuid' is not a UUID");
    }

    $ns_bytes = hex2bin(str_replace('-', '', $namespace_uuid));
    $hash = sha1($ns_bytes . $name);

    return sprintf(
      '%08s-%04s-%04x-%04x-%12s',
      substr($hash, 0, 8),
      substr($hash, 8, 4),
      (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
      (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
      substr($hash, 20, 12)
    );
  }
}
