<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\WorkerScenarios;

#[Group('wp')]
final class WpWorkerConformance extends WorkerScenarios {

  protected function createFixture(): HostFixture {
    return new WpHostFixture();
  }
}
