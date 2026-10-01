<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests\Mem;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Mem\MemHostFixture;
use TangibleDDD\Conformance\Scenarios\DecodeScenarios;

#[Group('mem')]
final class MemDecodeScenariosTest extends DecodeScenarios {

  protected function create_fixture(): HostFixture {
    return new MemHostFixture();
  }
}
