<?php

namespace TangibleDDD\Application\EventHandlers;

use TangibleDDD\Domain\Events\IDomainEvent;

interface IEventHandler {

  /**
   * @param IDomainEvent $event
   *
   * @return void
   */
  public function handle(IDomainEvent $event): void;
}

