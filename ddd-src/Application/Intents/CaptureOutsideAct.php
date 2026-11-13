<?php

namespace TangibleDDD\Application\Intents;

use TangibleDDD\IncorrectUsage;

/** Intents::capture() was called while no act (command pass) is executing. */
class CaptureOutsideAct extends IncorrectUsage {

  public function __construct() {
    parent::__construct('Intents::capture() must be called from inside a command handler (an act); no act is executing.');
  }
}
