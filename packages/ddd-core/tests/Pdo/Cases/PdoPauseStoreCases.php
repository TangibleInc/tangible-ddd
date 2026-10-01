<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Pdo\Cases;

use TangibleDDD\Core\Tests\Pdo\PdoTestCase;
use TangibleDDD\Defaults\Pdo\PdoPauseStore;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;

abstract class PdoPauseStoreCases extends PdoTestCase {

  private PdoPauseStore $pauses;

  protected function setUp(): void {
    parent::setUp();
    $this->pauses = new PdoPauseStore($this->db, self::PREFIX);
  }

  public function test_exact_wildcard_and_glob_selectors(): void {
    self::assertInstanceOf(IRelayPauseStore::class, $this->pauses);
    $now = self::utc('2026-10-01 12:00:00');

    self::assertFalse($this->pauses->is_paused('acme_order_placed', $now));

    $this->pauses->hold('ops', 'acme_user_joined', null);
    self::assertTrue($this->pauses->is_paused('acme_user_joined', $now));
    self::assertFalse($this->pauses->is_paused('acme_user_joined_late', $now));
    self::assertFalse($this->pauses->is_paused('ACME_USER_JOINED', $now), 'case-sensitive like fnmatch');

    $this->pauses->hold('deploy', 'acme_order_*', null);
    self::assertTrue($this->pauses->is_paused('acme_order_placed', $now));
    self::assertFalse($this->pauses->is_paused('acme_orders', $now));

    $this->pauses->hold('maint', 'billing_[ab]?', null);
    self::assertTrue($this->pauses->is_paused('billing_a1', $now));
    self::assertFalse($this->pauses->is_paused('billing_c1', $now));

    $this->pauses->hold('freeze', '*', null);
    self::assertTrue($this->pauses->is_paused('anything.at.all', $now));
  }

  public function test_two_holders_overlap_one_released_one_expires(): void {
    $t0 = self::utc('2026-10-01 12:00:00');
    $this->pauses->hold('deploy', 'acme_*', $t0->modify('+10 minutes'));
    $this->pauses->hold('ops', 'acme_order_placed', $t0->modify('+1 hour'));

    $this->pauses->release('ops');
    self::assertTrue($this->pauses->is_paused('acme_order_placed', $t0->modify('+5 minutes')), 'the remaining hold still pauses');
    self::assertFalse($this->pauses->is_paused('acme_order_placed', $t0->modify('+10 minutes')), 'until <= now is released');
  }

  public function test_hold_replaces_the_same_holder_and_selector(): void {
    $t0 = self::utc('2026-10-01 12:00:00');
    $this->pauses->hold('ops', 'acme_x', $t0->modify('+1 minute'));
    $this->pauses->hold('ops', 'acme_x', null);

    self::assertSame(1, $this->countRows('ddd_relay_pauses'));
    self::assertTrue($this->pauses->is_paused('acme_x', $t0->modify('+1 day')));
  }

  public function test_release_one_selector_or_all_of_a_holder(): void {
    $now = self::utc('2026-10-01 12:00:00');
    $this->pauses->hold('ops', 'a', null);
    $this->pauses->hold('ops', 'b', null);
    $this->pauses->hold('other', 'b', null);

    $this->pauses->release('ops', 'a');
    self::assertFalse($this->pauses->is_paused('a', $now));
    self::assertTrue($this->pauses->is_paused('b', $now));

    $this->pauses->release('ops');
    $this->pauses->release('other');
    self::assertFalse($this->pauses->is_paused('b', $now));
    self::assertSame(0, $this->countRows('ddd_relay_pauses'));
  }

  public function test_until_is_stored_as_utc_whatever_zone_it_was_given_in(): void {
    $until = new \DateTimeImmutable('2026-10-01 08:00:00', new \DateTimeZone('America/New_York'));
    $this->pauses->hold('ops', 'x', $until);

    self::assertSame('2026-10-01 12:00:00.000000', $this->row('ddd_relay_pauses', 'holder = ?', ['ops'])['held_until']);
    self::assertTrue($this->pauses->is_paused('x', self::utc('2026-10-01 11:59:59')));
    self::assertFalse($this->pauses->is_paused('x', self::utc('2026-10-01 12:00:00')));
  }

  public function test_active_patterns_are_the_live_selectors_as_regexes(): void {
    $now = self::utc('2026-10-01 12:00:00');
    $this->pauses->hold('a', 'acme_*', null);
    $this->pauses->hold('b', 'acme_*', null);
    $this->pauses->hold('c', 'gone', $now);

    self::assertSame(['^acme_.*$'], $this->pauses->patterns($now));
  }
}
