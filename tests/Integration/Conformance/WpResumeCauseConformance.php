<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\ResumeCauseScenarios;

/** ResumeCauseScenarios on wp (AW1): the resume cause lives in the `steps` JSON WpdbProcessStore persists whole. */
#[Group('wp')]
final class WpResumeCauseConformance extends ResumeCauseScenarios {

  protected function create_fixture(): HostFixture {
    return new WpHostFixture();
  }
}
