<?php

declare(strict_types=1);

namespace TangibleDDD\Runtime\Codec;

/**
 * A large scalar string (binary or not) carried in a fact payload (D6,
 * register 3.8): base64-wrapped, with a declared maximum size, a length and
 * a sha256 so a truncated or tampered value is detected on the way back.
 *
 * Use it as a constructor parameter type of an integration event (nullable
 * is fine); IntegrationBehaviour encodes it with toPayload() and revives it
 * with fromPayload(). The stored form is a JSON object:
 *
 *   {"__ddd_large_string": 1, "encoding": "base64", "bytes": n,
 *    "max_bytes": m, "sha256": "...", "data": "..."}
 *
 * Caps, in order:
 * - construction: strlen($value) > $maxBytes throws PayloadTooLarge, so
 *   the command fails before anything is staged;
 * - append: the whole encoded payload is capped by
 *   OutboxConfig::$max_payload_bytes (PayloadTooLarge, before commit);
 * - decode: a value over its stored max_bytes (or the caller's cap) is
 *   undecodable.
 *
 * Quarantine: fromPayload() throws UndecodableLargeString, whose
 * `quarantineReason` is the text a host stores in `quarantine_reason` (a
 * process row) or the ledger error (a fact, which then follows the
 * delivery budget's poison path).
 */
final class LargeString implements \Stringable {

  public const DEFAULT_MAX_BYTES = 4 * 1024 * 1024;

  public const MARKER = '__ddd_large_string';

  /** @throws PayloadTooLarge when $value is longer than $maxBytes */
  public function __construct(
    public readonly string $value,
    public readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
  ) {
    if ($maxBytes < 1) {
      throw new \InvalidArgumentException('LargeString maxBytes must be at least 1');
    }
    if (strlen($value) > $maxBytes) {
      throw new PayloadTooLarge('LargeString value', strlen($value), $maxBytes);
    }
  }

  public function bytes(): int {
    return strlen($this->value);
  }

  public function __toString(): string {
    return $this->value;
  }

  /** @return array{__ddd_large_string: int, encoding: string, bytes: int, max_bytes: int, sha256: string, data: string} */
  public function toPayload(): array {
    return [
      self::MARKER => 1,
      'encoding' => 'base64',
      'bytes' => strlen($this->value),
      'max_bytes' => $this->maxBytes,
      'sha256' => hash('sha256', $this->value),
      'data' => base64_encode($this->value),
    ];
  }

  public static function isEncoded(mixed $raw): bool {
    return is_array($raw) && isset($raw[self::MARKER]);
  }

  /**
   * @param int|null $maxBytes cap to enforce; null = the stored max_bytes
   * @throws UndecodableLargeString
   */
  public static function fromPayload(mixed $raw, ?int $maxBytes = null): self {
    if (!self::isEncoded($raw)) {
      throw new UndecodableLargeString('not an encoded LargeString (expected an object with ' . self::MARKER . ')');
    }
    /** @var array<string, mixed> $raw */
    if (($raw['encoding'] ?? null) !== 'base64') {
      throw new UndecodableLargeString(sprintf('unknown LargeString encoding %s', var_export($raw['encoding'] ?? null, true)));
    }
    if (!is_string($raw['data'] ?? null) || !is_int($raw['bytes'] ?? null) || !is_string($raw['sha256'] ?? null)) {
      throw new UndecodableLargeString('LargeString is missing data, bytes or sha256');
    }

    $cap = $maxBytes ?? (is_int($raw['max_bytes'] ?? null) ? $raw['max_bytes'] : self::DEFAULT_MAX_BYTES);
    if ($raw['bytes'] > $cap) {
      throw new UndecodableLargeString(sprintf('LargeString declares %d bytes, over its cap of %d', $raw['bytes'], $cap));
    }

    $value = base64_decode($raw['data'], true);
    if ($value === false) {
      throw new UndecodableLargeString('LargeString data is not valid base64');
    }
    if (strlen($value) !== $raw['bytes']) {
      throw new UndecodableLargeString(sprintf('LargeString length mismatch: declared %d bytes, decoded %d (truncated?)', $raw['bytes'], strlen($value)));
    }
    if (!hash_equals($raw['sha256'], hash('sha256', $value))) {
      throw new UndecodableLargeString('LargeString sha256 mismatch: the stored value was altered or corrupted');
    }

    return new self($value, max($cap, strlen($value)));
  }
}
