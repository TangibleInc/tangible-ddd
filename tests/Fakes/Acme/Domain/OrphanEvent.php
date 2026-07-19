<?php

namespace TangibleDDD\Tests\Fakes\Acme\Domain;

use TangibleDDD\Domain\Events\DomainEvent;

/** Used only in tests where NO consumer is registered — must fail loudly. */
class OrphanEvent extends DomainEvent {

  public function payload(): array {
    return [];
  }
}
