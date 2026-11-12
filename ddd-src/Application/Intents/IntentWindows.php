<?php

namespace TangibleDDD\Application\Intents;

use League\Tactician\CommandBus;
use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Correlation\Correlation;

/**
 * Static registry of capture windows, keyed by act id (the act's command_id).
 *
 * watch() marks an act as running under IntentsMiddleware; open()/close()
 * bracket one Intents::capture() call. unwatch() does NOT drop the act's
 * intents: the audit bracket still needs record() after the middleware has
 * returned; forget() does that.
 *
 * @internal
 */
final class IntentWindows {

  /** @var array<string, true> */
  private static array $watched = [];

  /** @var array<string, list<list<CapturedIntent>>> innermost window last */
  private static array $windows = [];

  /** @var array<string, list<CapturedIntent>> every intent of the act, capture order */
  private static array $intents = [];

  public static function watch(string $act_id): void {
    self::$watched[$act_id] = true;
  }

  public static function unwatch(string $act_id): void {
    unset(self::$watched[$act_id]);
  }

  public static function is_watched(string $act_id): bool {
    return isset(self::$watched[$act_id]);
  }

  public static function open(string $act_id): void {
    self::$windows[$act_id][] = [];
  }

  /** Pop the innermost window; returns only the intents captured in it. */
  public static function close(string $act_id): CapturedIntents {
    if( empty(self::$windows[$act_id]) ) {
      return new CapturedIntents([]);
    }
    $window = array_pop(self::$windows[$act_id]);
    if( empty(self::$windows[$act_id]) ) {
      unset(self::$windows[$act_id]);
    }
    return new CapturedIntents($window);
  }

  /**
   * Capture $command into the act's innermost window, if there is one. The
   * open window, not the watch, gates capture.
   */
  public static function try_capture(string $act_id, object $command): bool {
    if( empty(self::$windows[$act_id]) || !$command instanceof ICommand ) {
      return false;
    }

    $intent = new CapturedIntent($command, Correlation::current(), self::origin());
    self::$windows[$act_id][array_key_last(self::$windows[$act_id])][] = $intent;
    self::$intents[$act_id][] = $intent;
    return true;
  }

  /** @return list<CapturedIntent> */
  public static function undecided(string $act_id): array {
    return array_values(array_filter(
      self::$intents[$act_id] ?? [],
      fn(CapturedIntent $intent) => !$intent->is_decided()
    ));
  }

  /** @return list<array> */
  public static function record(string $act_id): array {
    return array_map(
      fn(CapturedIntent $intent) => $intent->to_record(),
      self::$intents[$act_id] ?? []
    );
  }

  public static function forget(string $act_id): void {
    unset(self::$intents[$act_id], self::$windows[$act_id]);
  }

  public static function reset(): void {
    self::$watched = [];
    self::$windows = [];
    self::$intents = [];
  }

  /**
   * file:line where the foreign code sent the command, anchored on the
   * dispatch: the innermost CommandBus::handle frame. A frame's file/line is
   * where that frame's function was CALLED. If handle() was called by a
   * send() on an ICommand (the CommandBusAware trait), the send frame's
   * file:line is the sender's call site; otherwise handle() was called
   * directly and its own file:line is. With no dispatch frame at all (a
   * direct try_capture call), the first frame outside the ddd package and
   * Tactician.
   */
  private static function origin(): string {
    $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

    foreach( $frames as $i => $frame ) {
      if( ($frame['class'] ?? null) !== CommandBus::class || $frame['function'] !== 'handle' ) {
        continue;
      }
      $caller = $frames[$i + 1] ?? null;
      $sent = $caller !== null
        && $caller['function'] === 'send'
        && is_a($caller['class'] ?? '', ICommand::class, true);
      $site = $sent ? $caller : $frame;
      if( isset($site['file'], $site['line']) ) {
        return $site['file'] . ':' . $site['line'];
      }
    }

    $package = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    $tactician = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'league' . DIRECTORY_SEPARATOR . 'tactician' . DIRECTORY_SEPARATOR;
    foreach( $frames as $frame ) {
      if( isset($frame['file'], $frame['line'])
        && !str_starts_with($frame['file'], $package)
        && !str_contains($frame['file'], $tactician) ) {
        return $frame['file'] . ':' . $frame['line'];
      }
    }
    return 'unknown';
  }
}
