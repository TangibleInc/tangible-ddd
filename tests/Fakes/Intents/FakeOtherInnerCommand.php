<?php

namespace TangibleDDD\Tests\Fakes\Intents;

use TangibleDDD\Application\Commands\ICommand;

class FakeOtherInnerCommand implements ICommand {

  public function send(): mixed {
    return null;
  }
}
