<?php

namespace TangibleDDD\Tests\Fakes\Acme\Application;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\CQRS\CommandBusAware;

/**
 * A consumer command with NO stamped base and NO container() override —
 * the trait's registry-resolved default supplies the bus (0.2.5c).
 */
class ShipWidget implements ICommand {
  use CommandBusAware;

  public function __construct(public readonly int $widget_id) {}
}
