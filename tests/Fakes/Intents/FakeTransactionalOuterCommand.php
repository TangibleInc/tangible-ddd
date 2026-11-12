<?php

namespace TangibleDDD\Tests\Fakes\Intents;

use TangibleDDD\Application\Commands\ICommand;
use TangibleDDD\Application\Commands\ITransactionalCommand;

class FakeTransactionalOuterCommand implements ICommand, ITransactionalCommand {

  public function send(): mixed {
    return null;
  }
}
