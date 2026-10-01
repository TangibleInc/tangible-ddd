<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use TangibleDDD\Conformance\HostFixture;
use TangibleDDD\Conformance\Scenarios\ConcurrencyScenarios;

/**
 * ConcurrencyScenarios on wp: worker 2 is a second MySQL connection (its
 * own GET_LOCK session) over the same database.
 */
#[Group('wp')]
final class WpConcurrencyConformance extends ConcurrencyScenarios {

  protected function create_fixture(): HostFixture {
    return new WpHostFixture();
  }

  /**
   * `-` on wp (register 3.7, section 4): GetLockProcessLock also takes the
   * legacy `ddd_process_<id>` name, which has no consumer component, so two
   * consumers with the same process id serialize while 0.6 copies may share
   * the site. Not applicable until the legacy name is retired.
   */
  #[Group('lock.namespace')]
  public function test_lock_namespace(): void {
    $this->skip_for('register 3.7 (lock.namespace is - on wp)', 'the wp lock also takes the legacy ddd_process_<id> name, so consumers with the same process id serialize by design while 0.6 copies can share the site');
  }
}
