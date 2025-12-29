<?php

namespace TangibleDDD\Application\Correlation;

/**
 * Request-scoped correlation context for tracing events across command boundaries.
 *
 * The correlation_id traces an entire chain of commands/events back to the root user action.
 * The command_id links to the current command in the audit log.
 *
 * This class uses static state because PHP is request-scoped - each request gets
 * fresh state. For CLI/async contexts, the context should be explicitly initialized
 * from stored correlation data.
 */
final class CorrelationContext {
  private static ?string $correlation_id = null;
  private static ?string $command_id = null;
  private static int $sequence = 0;

  /**
   * Initialize the correlation context.
   *
   * @param string|null $correlation_id If provided, uses this ID (for resuming chains).
   *                                    If null, generates a new ID (for new chains).
   */
  public static function init(?string $correlation_id = null): void {
    self::$correlation_id = $correlation_id ?? self::generate_id();
  }

  /**
   * Get the current correlation ID, generating one if not set.
   */
  public static function get(): string {
    return self::$correlation_id ?? (self::$correlation_id = self::generate_id());
  }

  /**
   * Get the current correlation ID without generating one.
   */
  public static function peek(): ?string {
    return self::$correlation_id;
  }

  /**
   * Set the correlation ID explicitly.
   */
  public static function set(string $correlation_id): void {
    self::$correlation_id = $correlation_id;
  }

  /**
   * Set the command ID (links to audit log).
   */
  public static function set_command_id(string $command_id): void {
    self::$command_id = $command_id;
  }

  /**
   * Get the current command ID.
   */
  public static function command_id(): ?string {
    return self::$command_id;
  }

  /**
   * Set the sequence number (for resuming from AS job).
   */
  public static function set_sequence(int $sequence): void {
    self::$sequence = $sequence;
  }

  /**
   * Get the current sequence number.
   */
  public static function sequence(): int {
    return self::$sequence;
  }

  /**
   * Increment and return the next sequence number.
   */
  public static function next_sequence(): int {
    return ++self::$sequence;
  }

  /**
   * Reset the context (should be called after command completes).
   */
  public static function reset(): void {
    self::$correlation_id = null;
    self::$command_id = null;
    self::$sequence = 0;
  }

  /**
   * Generate a UUID v4 for correlation.
   */
  private static function generate_id(): string {
    try {
      return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
      );
    } catch (\Exception $e) {
      // Fallback if random_int fails
      return sprintf(
        '%08x-%04x-%04x-%04x-%012x',
        time(),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffffffffffff)
      );
    }
  }
}
