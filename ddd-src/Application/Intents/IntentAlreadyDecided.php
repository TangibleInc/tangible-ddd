<?php

namespace TangibleDDD\Application\Intents;

use TangibleDDD\IncorrectUsage;

/** An act decided a captured intent that already had a decision. */
class IntentAlreadyDecided extends IncorrectUsage {

  public function __construct(CapturedIntent $intent) {
    parent::__construct(sprintf(
      'Intent %s is already %s and cannot be decided again.',
      get_class($intent->command),
      $intent->decision()->value
    ));
  }
}
