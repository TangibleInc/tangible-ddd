<?php

declare(strict_types=1);

namespace TangibleDDD\Symfony\Tests\Integration\Persistence;

use TangibleDDD\Symfony\Persistence\DbalRelayPauseStore;
use TangibleDDD\Symfony\Tests\Integration\PostgresTestCase;

final class DbalRelayPauseStoreTest extends PostgresTestCase {

  private \DateTimeImmutable $now;

  protected function setUp(): void {
    parent::setUp();
    $this->now = new \DateTimeImmutable('2026-10-01T12:00:00Z');
  }

  public function test_exact_wildcard_and_glob_selectors_pause_matching_types(): void {
    $store = new DbalRelayPauseStore($this->db);

    self::assertFalse($store->is_paused('acme_order_placed', $this->now));

    $store->hold('ops', 'acme_order_*', null);
    self::assertTrue($store->is_paused('acme_order_placed', $this->now));
    self::assertFalse($store->is_paused('acme_invoice_sent', $this->now));

    $store->hold('deploy', 'acme_invoice_sent', null);
    self::assertTrue($store->is_paused('acme_invoice_sent', $this->now));

    $store->hold('freeze', '*', null);
    self::assertTrue($store->is_paused('anything_at_all', $this->now));
  }

  public function test_overlapping_holders_one_released_one_expired_the_rest_still_holds(): void {
    $store = new DbalRelayPauseStore($this->db);
    $store->hold('a', 'widget_registered', null);
    $store->hold('b', 'widget_registered', $this->now->modify('+10 minutes'));
    $store->hold('c', 'widget_registered', $this->now->modify('+5 minutes'));

    $store->release('a');
    self::assertTrue($store->is_paused('widget_registered', $this->now), 'b and c still hold');

    $later = $this->now->modify('+6 minutes');
    self::assertTrue($store->is_paused('widget_registered', $later), 'c expired, b still holds');

    $store->release('b', 'widget_registered');
    self::assertFalse($store->is_paused('widget_registered', $later));
  }

  public function test_expiry_at_exactly_now_means_released(): void {
    $store = new DbalRelayPauseStore($this->db);
    $store->hold('a', 'x', $this->now);
    self::assertFalse($store->is_paused('x', $this->now));
  }

  public function test_hold_for_the_same_pair_replaces_it(): void {
    $store = new DbalRelayPauseStore($this->db);
    $store->hold('a', 'x', $this->now->modify('+1 minute'));
    $store->hold('a', 'x', null);

    self::assertTrue($store->is_paused('x', $this->now->modify('+1 day')));
    self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_relay_pauses'));
  }

  public function test_release_of_one_selector_keeps_the_holders_other_selectors(): void {
    $store = new DbalRelayPauseStore($this->db);
    $store->hold('a', 'x', null);
    $store->hold('a', 'y', null);

    $store->release('a', 'x');

    self::assertFalse($store->is_paused('x', $this->now));
    self::assertTrue($store->is_paused('y', $this->now));
  }

  public function test_active_patterns_are_anchored_regexes_for_the_outbox_claim(): void {
    $store = new DbalRelayPauseStore($this->db);
    $store->hold('a', 'acme_order_*', null);
    $store->hold('b', 'gone', $this->now->modify('-1 second'));

    $patterns = $store->patterns($this->now);

    self::assertCount(1, $patterns);
    self::assertSame(1, preg_match('/' . $patterns[0] . '/', 'acme_order_placed'));
    self::assertSame(0, preg_match('/' . $patterns[0] . '/', 'xacme_order_placed'));
  }

  public function test_uses_the_table_prefix(): void {
    $this->db->executeStatement('DROP TABLE IF EXISTS p_ddd_relay_pauses');
    \TangibleDDD\Symfony\Persistence\PostgresSchema::apply($this->db, 'p_');
    $store = new DbalRelayPauseStore($this->db, 'p_');

    $store->hold('a', 'x', null);

    self::assertSame(1, (int) $this->db->fetchOne('SELECT count(*) FROM p_ddd_relay_pauses'));
    self::assertSame(0, (int) $this->db->fetchOne('SELECT count(*) FROM ddd_relay_pauses'));
    foreach (\TangibleDDD\Symfony\Persistence\PostgresSchema::tables() as $t) {
      $this->db->executeStatement('DROP TABLE IF EXISTS p_' . $t . ' CASCADE');
    }
  }
}
