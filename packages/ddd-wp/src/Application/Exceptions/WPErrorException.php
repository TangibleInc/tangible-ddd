<?php

namespace TangibleDDD\Application\Exceptions;

/**
 * Wraps a WP_Error as a proper exception for use in DDD code.
 */
class WPErrorException extends \Exception {
  
  public function __construct( private readonly \WP_Error $error ) {
    parent::__construct($error->get_error_message());
  }

  public function get_wp_error(): \WP_Error {
    return $this->error;
  }
}

