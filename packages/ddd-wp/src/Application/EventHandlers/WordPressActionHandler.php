<?php

namespace TangibleDDD\Application\EventHandlers;

use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Events\IEventFromArgs;

abstract class WordPressActionHandler implements IEventHandler {

  use \TangibleDDD\Application\Events\RaisesEvents;

  protected function get_number_of_args(): int {
    return 10; // Generous default so it never fails
  }

  /**
   * @return class-string<IDomainEvent>
   */
  abstract protected function get_event_class(): string;

  protected function create_domain_event(array $params): IDomainEvent {
    $cls = static::get_event_class();

    if ( is_subclass_of( $cls, IEventFromArgs::class ) ) {
      /** @var class-string<IEventFromArgs> $cls */
      return $cls::from_args( $params );
    }

    $ref = new \ReflectionClass( $cls );
    /** @var IDomainEvent $event */
    $event = $ref->newInstanceArgs( $params );
    return $event;
  }

  /**
   * The optional unit of work feeds the act-level $this->event() lane
   * (RaisesEvents), mirroring WorkflowHandler: a synchronous reaction may
   * record follow-on facts mid-drain (the seal admits IAnnouncesIntegration)
   * without wiring the trait plumbing itself. Subclasses that never raise
   * keep calling parent::__construct() bare; without it event() throws
   * rather than silently dropping a moment.
   */
  public function __construct(
    protected readonly ?\TangibleDDD\Application\Events\EventsUnitOfWork $events_uow = null,
  ) {
    $domain_action = $this->get_event_class()::action();

    add_action( $domain_action, function ( ...$params ) {
      $event = $this->create_domain_event( $params );

      // Reactions ledger: time the run and record against the frame the
      // dispatcher opened — $event here is a RECONSTRUCTION, not the
      // published instance, so attribution goes through the stack. A
      // throwing handler is recorded with its error and RETHROWN.
      $start = microtime( true );
      try {
        $this->handle( $event );
      } catch ( \Throwable $error ) {
        Reactions::record( static::class, (int) round( ( microtime( true ) - $start ) * 1000 ), $error );
        throw $error;
      }
      Reactions::record( static::class, (int) round( ( microtime( true ) - $start ) * 1000 ) );
    }, 10, $this->get_number_of_args() );
  }

  protected function events_uow(): ?\TangibleDDD\Application\Events\EventsUnitOfWork {
    return $this->events_uow;
  }
}

