<?php

namespace TangibleDDD\Application\Exceptions;

/**
 * Thrown when a plain domain event is recorded after the unit of work has been
 * sealed — i.e. from inside an event handler, during the publish/drain phase.
 *
 * The rule: once the command handler returns, only integration events may be
 * recorded. Domain event handlers run synchronously inside the command's
 * transaction and may cause aggregate writes, but those aggregates may only
 * emit integration events (which terminate at the outbox). Emitting a further
 * domain event from a handler would re-enter synchronous in-process dispatch
 * and risk an unbounded cascade within the same transaction.
 */
class DomainEventAfterSealException extends ApplicationException {
  public function __construct(string $event_class) {
    parent::__construct(sprintf(
      'Domain event %s was recorded after the unit of work was sealed. '
      . 'Event handlers may only emit integration events.',
      $event_class
    ));
  }
}
