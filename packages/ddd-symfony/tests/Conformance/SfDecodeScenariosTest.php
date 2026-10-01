<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Application\Process\StartMode;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\DecodeScenarios;

#[Group('sf')]
#[Group('conformance')]
final class SfDecodeScenariosTest extends DecodeScenarios {

  protected function createFixture(): HostFixture {
    return new SfHostFixture(StartMode::InBand);
  }
}
