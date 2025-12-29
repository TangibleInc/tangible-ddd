<?php

namespace TangibleDDD\Application\EventHandlers;

use TangibleDDD\Domain\Events\IDomainEvent;
use TangibleDDD\Domain\Events\IEventFromArgs;

abstract class WordPressActionHandler implements IEventHandler {

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

  public function __construct() {
    $domain_action = $this->get_event_class()::action();

    add_action( $domain_action, function ( ...$params ) {
      $event = $this->create_domain_event( $params );
      $this->handle( $event );
    }, 10, $this->get_number_of_args() );
  }
}

