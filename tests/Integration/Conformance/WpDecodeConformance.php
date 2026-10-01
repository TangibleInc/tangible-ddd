<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\DecodeScenarios;

/** DecodeScenarios on wp (register section 8, wave 4, wp). */
#[Group('wp')]
final class WpDecodeConformance extends DecodeScenarios {

  protected function create_fixture(): HostFixture {
    return new WpHostFixture();
  }
}
