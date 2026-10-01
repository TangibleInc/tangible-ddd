<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\WorkItemScenarios;

#[Group('sf')]
#[Group('conformance')]
final class SfWorkItemScenariosTest extends WorkItemScenarios {

  protected function create_fixture(): HostFixture {
    return new SfHostFixture();
  }
}
