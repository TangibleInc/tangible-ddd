<?php

namespace TangibleDDD\Application\EventHandlers;

/**
 * Event handler that automatically queues the actual handling
 * to run asynchronously via Action Scheduler.
 * 
 * When the domain event fires, it enqueues an async action.
 * The actual handle() method runs in a separate request.
 */
abstract class AsyncWordPressActionHandler extends WordPressActionHandler {

  public function __construct() {
    $domain_action = $this->get_event_class()::action();
    $async_action = 'async_' . $domain_action;

    // When event fires, enqueue async job
    add_action( $domain_action, function ( ...$params ) use ( $async_action ) {
      as_enqueue_async_action( $async_action, $params );
    }, 10, $this->get_number_of_args() );

    // When async job runs, handle the event
    add_action( $async_action, function ( ...$params ) {
      $event = $this->create_domain_event( $params );
      $this->handle( $event );
    }, 10, $this->get_number_of_args() );
  }
}

