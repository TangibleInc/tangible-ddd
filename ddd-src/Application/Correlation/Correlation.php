<?php

namespace TangibleDDD\Application\Correlation;

/**
 * The runtime facade (0.3, spec §5): static ACCESS to immutable TraceContext
 * values — you correlate as you go (present tense); the read side traces
 * after the fact. Deliberately NOT named CorrelationContext: the old
 * god-class dissolves into this facade + the value, and old/new must never
 * be confusable in a diff.
 *
 * One bracket verb: within() pushes a full snapshot — the context AND the
 * sequence counter — runs, restores on the way out (exceptions included).
 * The per-scope counter snapshot is forced by the cross-story wake: a saga
 * from story A woken by story B's fact runs nested inside B's drain, and
 * each story's position advances only while that story is current.
 *
 * This class is the single worker-mode seam: under FrankenPHP/Swoole the
 * storage below becomes context-local without any call site changing.
 */
final class Correlation {

  private static ?TraceContext $current = null;

  /** @var list<array{ctx: ?TraceContext, seq: int}> */
  private static array $stack = [];

  private static int $sequence = 0;

  /** The ambient context; a flat context lazily mints a new story. */
  public static function current(): TraceContext {
    return self::$current ??= TraceContext::root();
  }

  /**
   * Run $work inside $ctx. The ONE scope verb — drains, command passes, and
   * saga wakes are all this bracket with different derivations.
   */
  public static function within(TraceContext $ctx, callable $work): mixed {
    self::$stack[] = ['ctx' => self::$current, 'seq' => self::$sequence];
    self::$current = $ctx;
    self::$sequence = $ctx->sequence;

    try {
      return $work();
    } finally {
      $frame = array_pop(self::$stack);
      self::$current = $frame['ctx'];
      self::$sequence = $frame['seq'];
    }
  }

  /** Mint the next story position (per-story; snapshot-restored by within). */
  public static function next_sequence(): int {
    return ++self::$sequence;
  }

  /** Test seam. */
  public static function reset(): void {
    self::$current = null;
    self::$stack = [];
    self::$sequence = 0;
  }
}
