<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\FreshProcessScenarios;

/**
 * The multi-process scenarios on wp: every fresh process is a separate
 * `php bin/fresh.php` run that boots WordPress against the same database
 * (WpHostFixture::freshProcess()), and a kill is SIGKILL.
 */
#[Group('wp')]
final class WpFreshProcessConformance extends FreshProcessScenarios {

  protected function create_fixture(): HostFixture {
    return new WpHostFixture();
  }
}
