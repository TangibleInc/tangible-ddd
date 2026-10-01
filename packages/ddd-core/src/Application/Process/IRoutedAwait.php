<?php

declare(strict_types=1);

namespace TangibleDDD\Application\Process;

use TangibleDDD\Runtime\Process\AwaitRoute;

/**
 * An await mechanism that names its index routes (D3): one per fact class
 * and key it still waits for. A mechanism without this interface has the
 * single route (event_class(), ''). See AwaitRoute for the key rule.
 */
interface IRoutedAwait {

  /** @return list<AwaitRoute> the routes still open (gathered keys are gone) */
  public function routes(): array;
}
