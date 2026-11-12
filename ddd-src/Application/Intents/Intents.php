<?php

namespace TangibleDDD\Application\Intents;

use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;

/**
 * The public entry point: a handler wraps a call into foreign code in
 * capture(); commands that code sends are captured instead of throwing.
 */
final class Intents {

  /**
   * Run $call with a capture window open for the current act and return the
   * intents it produced. If $call throws, the intents stay registered with
   * the act (undecided, so the audit row shows them) and the throw propagates.
   */
  public static function capture(callable $call): CapturedIntents {
    $cause = Correlation::peek()?->cause;
    if( $cause === null || $cause->kind !== Kind::Act ) {
      throw new CaptureOutsideAct();
    }

    $act_id = $cause->id;
    if( !IntentWindows::is_watched($act_id) ) {
      throw new IntentsMiddlewareMissing();
    }

    IntentWindows::open($act_id);
    try {
      $call();
    } finally {
      $captured = IntentWindows::close($act_id);
    }
    return $captured;
  }
}
