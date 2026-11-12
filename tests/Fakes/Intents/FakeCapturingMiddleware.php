<?php

namespace TangibleDDD\Tests\Fakes\Intents;

use League\Tactician\Middleware;
use TangibleDDD\Application\Intents\IntentWindows;

/** Stands in for the guard: tries to capture every command it sees. */
class FakeCapturingMiddleware implements Middleware {

  public function __construct(private string $act_id) {}

  public function execute(object $command, callable $next): mixed {
    return IntentWindows::try_capture($this->act_id, $command) ? null : $next($command);
  }
}
