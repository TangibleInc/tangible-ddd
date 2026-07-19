<?php

namespace TangibleDDD\Application\Correlation;

/**
 * @deprecated 0.3 — the god-class dissolved (docs/0.3-trace-context.md §8).
 *
 * What replaced each shelf:
 *  - correlation / causation / sequence  → the TraceContext VALUE, scoped by
 *    Correlation::within() (the facade is the ONLY ambient authority)
 *  - execution frames                    → Correlation::current()->cause->kind
 *  - scope stack / enter / leave / with  → Correlation::within()'s snapshots
 *  - command_id                          → the ambient Act cause's id
 *
 * This shim survives for exactly three callers, all consumer-side (ledger:
 * docs/migration-0.2-to-0.3.md):
 *  1. CorrelationContext::get() reads in consumer handlers/services — served
 *     FACADE-FIRST, so inside any bracket they see the true ambient story;
 *  2. IntegrationEnvelope::restore_context() (datastream's relay publisher),
 *     which writes the legacy slots below; the act bracket and the runner
 *     read them as a fallback when no facade scope is open;
 *  3. command_id() reads in consumer test fixtures — facade-first: the
 *     ambient Act cause's id, else the legacy slot.
 *
 * The class dies when the callers migrate. Do not add readers or writers.
 */
final class CorrelationContext {

  private static ?string $correlation_id = null;
  private static ?string $command_id = null;
  private static int $sequence = 0;
  private static ?string $causation_id = null;
  private static ?string $causation_type = null;

  public static function init(?string $correlation_id = null): void {
    self::$correlation_id = $correlation_id ?? \TangibleDDD\Domain\Shared\Uuid::v4();
  }

  /** Facade-first: inside any bracket, the true ambient story wins. */
  public static function get(): string {
    return Correlation::peek()?->correlation_id
      ?? self::$correlation_id
      ?? (self::$correlation_id = \TangibleDDD\Domain\Shared\Uuid::v4());
  }

  public static function peek(): ?string {
    return Correlation::peek()?->correlation_id ?? self::$correlation_id;
  }

  public static function set(string $correlation_id): void {
    self::$correlation_id = $correlation_id;
  }

  public static function set_command_id(string $command_id): void {
    self::$command_id = $command_id;
  }

  /** Facade-first: inside an act, the ambient Act cause's id. */
  public static function command_id(): ?string {
    $cause = Correlation::peek()?->cause;
    if ($cause?->kind === Kind::Act) {
      return $cause->id;
    }

    return self::$command_id;
  }

  public static function set_causation(string $causation_id, string $causation_type): void {
    self::$causation_id = $causation_id;
    self::$causation_type = $causation_type;
  }

  public static function causation_id(): ?string {
    return self::$causation_id;
  }

  public static function causation_type(): ?string {
    return self::$causation_type;
  }

  public static function clear_causation(): void {
    self::$causation_id = null;
    self::$causation_type = null;
  }

  public static function set_sequence(int $sequence): void {
    self::$sequence = $sequence;
  }

  public static function sequence(): int {
    return self::$sequence;
  }

  public static function next_sequence(): int {
    return ++self::$sequence;
  }

  public static function reset(): void {
    self::$correlation_id = null;
    self::$command_id = null;
    self::$sequence = 0;
    self::$causation_id = null;
    self::$causation_type = null;
  }
}
