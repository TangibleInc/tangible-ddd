<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\LockScenarios;

/** LockScenarios on wp: the per-process lock on wp (GET_LOCK on both names). */
#[Group('wp')]
final class WpLockConformance extends LockScenarios {

  protected function create_fixture(): HostFixture {
    return new WpHostFixture();
  }
}
