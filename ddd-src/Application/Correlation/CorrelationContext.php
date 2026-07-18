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
   * Causation — the direct parent that caused the NEXT command to be dispatched.
   *
   * Distinct from correlation: correlation_id is the whole-trace id (the root);
   * causation is the single parent edge (one hop up). By doctrine the parent is
   * one of exactly two coordination modes:
   *   - 'integration_event' (choreography) → causation_id = the event_id
   *   - 'long_process'      (orchestration) → causation_id = the process_id
   * Root commands (user/cli/system) have no causation — both stay null.
   *
   * Set by the causer (integration boundary / ProcessRunner) immediately before
   * dispatch; consumed (cleared) once the command it precedes records it.
   */
  private static ?string $causation_id = null;
  private static ?string $causation_type = null;

  /**
   * Execution frames — which machinery owns the current execution window.
   *
   * The context itself is a dumb frame-holder: the COMMAND frame is written
   * only by CorrelationMiddleware (around every bus pass), the PROCESS frame
   * only by ProcessRunner's sealed bracket (around every saga wake). The
   * legality guards are readers: a command dispatched while the command
   * frame is occupied throws (no command inside a command); a process
   * started while it's occupied throws (handlers announce facts instead).
   * Command-inside-process-scope — the saga ground contact — never trips
   * anything, because the guards read the command frame only.
   */
  private static ?string $command_frame = null;
  private static ?string $process_frame = null;

  /**
   * Stack of active correlation scopes (most recent on top).
   *
   * Each frame is a correlation id. The stack gates teardown: the context is
   * only fully cleared when the OUTERMOST scope exits (stack empties). This is
   * what lets a boundary that wraps work — a LongProcess run, an integration
   * callback — keep its correlation alive across the commands it dispatches,
   * instead of the first command's exit wiping it.
   *
   * Today a frame is just an id (used for depth + parent-correlation restore).
   * It is intentionally a stack, not a counter, so frames can later carry a
   * span id + kind (command|process|workflow) for parent/causation edges
   * without reworking this class.
   *
   * @var string[]
   */
  private static array $scope = [];

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
   * Stamp the causation of the next command to be dispatched.
   *
   * @param string $causation_id   parent span id (event_id or process_id)
   * @param string $causation_type 'integration_event' | 'long_process'
   */
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

  /**
   * Consume the causation. Called after one command records it so it never
   * bleeds into a sibling or the worker's next command.
   */
  public static function clear_causation(): void {
    self::$causation_id = null;
    self::$causation_type = null;
  }

  /** Mark the command whose bus pass is currently executing. */
  public static function mark_command_frame(string $command_class): void {
    self::$command_frame = $command_class;
  }

  public static function command_frame(): ?string {
    return self::$command_frame;
  }

  public static function clear_command_frame(): void {
    self::$command_frame = null;
  }

  /** Mark the process whose wake bracket is currently executing. */
  public static function mark_process_frame(string $process_id): void {
    self::$process_frame = $process_id;
  }

  public static function process_frame(): ?string {
    return self::$process_frame;
  }

  public static function clear_process_frame(): void {
    self::$process_frame = null;
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
   * Enter a correlation scope.
   *
   * If $correlation_id is given, that id becomes current (restore/resume — e.g.
   * a LongProcess continuation or an integration callback). If omitted, the
   * current id is inherited, or a fresh one generated when none exists (a new
   * top-level command). Pushes a frame so a matching leave() can unwind.
   */
  public static function enter(?string $correlation_id = null): void {
    $id = $correlation_id ?? self::$correlation_id ?? self::generate_id();
    self::$scope[] = $id;
    self::$correlation_id = $id;
  }

  /**
   * Leave the current correlation scope.
   *
   * Pops one frame. If that was the outermost frame the whole context is reset;
   * otherwise the parent scope's correlation id is restored. Command-local state
   * (command_id) is cleared on every leave so it never bleeds between siblings.
   */
  public static function leave(): void {
    array_pop(self::$scope);
    self::$command_id = null;

    if (self::$scope === []) {
      self::reset();
      return;
    }

    self::$correlation_id = self::$scope[array_key_last(self::$scope)];
  }

  /**
   * Run $fn inside a correlation scope, guaranteeing the scope is left even on
   * throw. The canonical way for a boundary (process runner, integration
   * callback) to wrap the work it dispatches.
   */
  public static function with(string $correlation_id, callable $fn): mixed {
    self::enter($correlation_id);
    try {
      return $fn();
    } finally {
      self::leave();
    }
  }

  /**
   * Current scope depth (0 = no active scope).
   */
  public static function depth(): int {
    return count(self::$scope);
  }

  /**
   * Reset the context (should be called after command completes).
   */
  public static function reset(): void {
    self::$correlation_id = null;
    self::$command_id = null;
    self::$sequence = 0;
    self::$causation_id = null;
    self::$causation_type = null;
    self::$scope = [];
    self::$command_frame = null;
    self::$process_frame = null;
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
