<?php

namespace TangibleDDD\Application\Intents;

use TangibleDDD\IncorrectUsage;

/**
 * An act ended with captured intents nobody decided. Every intent a window
 * captures must be fulfilled or rejected before the act ends, including the
 * ones captured before the window's callback threw.
 */
class UndecidedIntents extends IncorrectUsage {

  /** @param list<CapturedIntent> $intents */
  public function __construct(array $intents) {
    $listed = array_map(
      fn(CapturedIntent $intent) => get_class($intent->command) . ' from ' . $intent->origin,
      $intents
    );
    parent::__construct(sprintf(
      '%d captured intent(s) left undecided: %s. Decide each with fulfil() or reject() before the act ends.',
      count($intents),
      implode('; ', $listed)
    ));
  }
}
