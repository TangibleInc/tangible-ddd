<?php

namespace TangibleDDD\Application\Intents;

use TangibleDDD\IncorrectUsage;

/** IntentsMiddleware ran without an act scope: it is not inside CorrelationMiddleware. */
class IntentsMiddlewareMisplaced extends IncorrectUsage {

  public function __construct() {
    parent::__construct(
      'IntentsMiddleware must sit inside CorrelationMiddleware on the command bus: '
      . 'it found no act scope. Add it between TransactionMiddleware and DomainEventsPublishMiddleware.'
    );
  }
}
