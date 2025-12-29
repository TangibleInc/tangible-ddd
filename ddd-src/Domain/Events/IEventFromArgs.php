<?php

namespace TangibleDDD\Domain\Events;

interface IEventFromArgs extends IDomainEvent {
  public static function from_args(array $args): IDomainEvent;
}

