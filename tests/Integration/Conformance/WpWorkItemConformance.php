<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\WorkItemScenarios;

/** WorkItemScenarios on wp (W4): the core WorkflowHandler over the wp behaviour-workflow store and work-item ledger. */
#[Group('wp')]
final class WpWorkItemConformance extends WorkItemScenarios {

  protected function create_fixture(): HostFixture {
    return new WpHostFixture();
  }
}
