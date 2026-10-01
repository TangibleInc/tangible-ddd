<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\CodecScenarios;

/** CodecScenarios on wp (register section 8, wave 4, wp). */
#[Group('wp')]
final class WpCodecConformance extends CodecScenarios {

  protected function createFixture(): HostFixture {
    return new WpHostFixture();
  }
}
