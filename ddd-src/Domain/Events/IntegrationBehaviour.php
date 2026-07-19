<?php

namespace TangibleDDD\Domain\Events;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * The record capability: strict scalarise codec (ctor IS the schema),
 * total hydration, identity announcement. (Journey slots died in 0.3 —
 * identity lives on the envelope and the outbox row; PublishedFacts
 * carries the re-raise guard.)
 *
 * Used by the IntegrationEvent base (twins) and mixed into scalar
 * DomainEvents (self-publishers). Host class must provide static prefix()
 * and name() (both via the Event root).
 */
trait IntegrationBehaviour {

  public static function integration_action(): string {
    return static::prefix() . '_integration_' . static::name();
  }

  // ── scalarise: the membrane, strict ─────────────────────────────────
  /** @throws NonReversibleValue */
  public function integration_payload(): array {
    $out = [];
    foreach (self::record_schema() as $param) {
      $name = $param->getName();
      $out[$name] = self::scalarise_value($this->{$name}, $name);
    }
    return $out;
  }

  // ── hydrate: the return ticket ──────────────────────────────────────
  public static function from_payload(array $payload): static {
    $args = [];
    foreach (self::record_schema() as $param) {
      $name = $param->getName();
      $raw = array_key_exists($name, $payload)
        ? $payload[$name]
        : ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
      $type = $param->getType();
      $args[$name] = self::revive($type instanceof ReflectionNamedType ? $type : null, $raw);
    }
    return new static(...$args);
  }

  // ── identity: READ-ONLY, publication-derived (0.3) ───────────────────
  // Facts carry no mutable slots; these deprecated accessors answer from
  // PublishedFacts — null on fresh/hydrated instances, populated the moment
  // the bus writes the outbox row. stamp_journey() is gone.

  /** @deprecated 0.3 — the story lives on the envelope/scope, not the fact. */
  public function correlation_id(): ?string {
    return \TangibleDDD\Application\Events\PublishedFacts::correlation_of($this);
  }

  /** @deprecated 0.3 — at-rest identity is the outbox row. */
  public function event_id(): ?string {
    return \TangibleDDD\Application\Events\PublishedFacts::id_of($this);
  }

  // ── identity announcement (self-publishers; twins never announce) ───
  public function to_integration(): static {
    return $this;
  }

  public function delay(): int { return 0; }
  public function is_unique(): bool { return false; }

  // ── internals ───────────────────────────────────────────────────────
  /** @throws NonReversibleValue */
  private static function scalarise_value(mixed $v, string $param): mixed {
    return match (true) {
      $v === null || is_scalar($v)    => $v,
      $v instanceof BackedEnum         => $v->value,
      $v instanceof DateTimeInterface  => $v->format('c'),
      is_array($v)                     => array_map(
        fn($e) => self::scalarise_value($e, $param), $v
      ),
      default => throw new NonReversibleValue(static::class, $param, get_debug_type($v)),
    };
  }

  private static function revive(?ReflectionNamedType $type, mixed $raw): mixed {
    if ($raw === null || $type === null) {
      return $raw;
    }
    $t = $type->getName();
    return match (true) {
      $t === 'int'    => (int) $raw,
      $t === 'float'  => (float) $raw,
      $t === 'string' => (string) $raw,
      $t === 'bool'   => (bool) $raw,
      is_a($t, BackedEnum::class, true)        => $t::from($raw),
      is_a($t, DateTimeInterface::class, true) => new DateTimeImmutable($raw),
      default => $raw,
    };
  }

  /** @return ReflectionParameter[] */
  private static function record_schema(): array {
    static $schema = [];
    return $schema[static::class]
      ??= (new ReflectionClass(static::class))->getConstructor()?->getParameters() ?? [];
  }
}
