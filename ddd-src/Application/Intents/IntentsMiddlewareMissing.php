<?php

namespace TangibleDDD\Application\Intents;

use TangibleDDD\IncorrectUsage;

/** Intents::capture() ran in an act whose command bus has no IntentsMiddleware. */
class IntentsMiddlewareMissing extends IncorrectUsage {

  public function __construct() {
    parent::__construct(
      'Intents::capture() needs IntentsMiddleware on the command bus: add it between '
      . 'TransactionMiddleware and DomainEventsPublishMiddleware.'
    );
  }
}
