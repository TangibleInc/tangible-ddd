<?php

namespace TangibleDDD\Tests\Fakes\Intents;

use TangibleDDD\Application\Commands\ICommand;

class FakeOuterCommand implements ICommand {

  public function send(): mixed {
    return null;
  }
}
