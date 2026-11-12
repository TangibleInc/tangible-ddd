<?php

namespace TangibleDDD\Application\Intents;

use League\Tactician\Middleware;
use TangibleDDD\Application\Correlation\Correlation;

/**
 * The end-of-act check. Placed between TransactionMiddleware and
 * DomainEventsPublishMiddleware, so CorrelationMiddleware has already opened
 * the act scope, and the check runs after synchronous domain-event handlers
 * and still inside the transaction: an act that ends with an undecided
 * intent throws UndecidedIntents and its transaction rolls back.
 *
 * Watching the act is also what lets Intents::capture() open a window in it.
 */
final class IntentsMiddleware implements Middleware {

  public function execute(object $command, callable $next) {
    $act_id = Correlation::current()->cause->id;
    IntentWindows::watch($act_id);

    try {
      // A throw from $next propagates as is: the act already failed, and
      // its undecided intents still reach the audit row.
      $result = $next($command);

      $undecided = IntentWindows::undecided($act_id);
      if( $undecided !== [] ) {
        throw new UndecidedIntents($undecided);
      }
      return $result;

    } finally {
      IntentWindows::unwatch($act_id);
    }
  }
}
