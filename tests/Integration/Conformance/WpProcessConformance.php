<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\ProcessScenarios;

/** ProcessScenarios on wp: the process.* scenarios on the v8 wp ports. */
#[Group('wp')]
final class WpProcessConformance extends ProcessScenarios {

  protected function createFixture(): HostFixture {
    return new WpHostFixture();
  }
}
