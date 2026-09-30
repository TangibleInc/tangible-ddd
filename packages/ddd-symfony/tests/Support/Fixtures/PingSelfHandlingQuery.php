<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Support\Fixtures;

use TangibleDDD\Application\Queries\SelfHandlingQuery;

/** handle() needs the PingMarker interface (an alias id). */
final class PingSelfHandlingQuery extends SelfHandlingQuery {

  protected function handle(PingMarker $marker): string {
    return 'pong';
  }
}
