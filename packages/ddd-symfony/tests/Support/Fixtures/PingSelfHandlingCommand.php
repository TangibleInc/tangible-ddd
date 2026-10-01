<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Commands\SelfHandlingCommand;

/** handle() needs PingListener (a class id) and a scalar with a default (not a service). */
final class PingSelfHandlingCommand extends SelfHandlingCommand {

  public function __construct(public readonly string $label) {}

  protected function handle(PingListener $listener, int $times = 1): void {}
}
