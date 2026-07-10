<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Commands\ICommand;

class FakeCapturingCommand implements ICommand {
  /** @var array<self> */
  public static array $sent = [];

  public function __construct(public readonly int $request_id) {}

  public function send(): mixed {
    self::$sent[] = $this;
    return null;
  }
}
