<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\Outbox\IRelayPauseStore;
use TangibleDDD\Testing\InMemoryRelayPauseStore;

final class InMemoryRelayPauseStoreTest extends TestCase {

  private function at(string $t): \DateTimeImmutable {
    return new \DateTimeImmutable($t, new \DateTimeZone('UTC'));
  }

  public function test_exact_and_wildcard_selectors(): void {
    $p = new InMemoryRelayPauseStore();
    self::assertInstanceOf(IRelayPauseStore::class, $p);

    $p->hold('ops', 'acme_order_placed', null);
    self::assertTrue($p->is_paused('acme_order_placed', $this->at('now')));
    self::assertFalse($p->is_paused('acme_order_shipped', $this->at('now')));

    $p->hold('deploy', '*', null);
    self::assertTrue($p->is_paused('anything', $this->at('now')));
  }

  public function test_overlapping_holders_one_released_one_expiring(): void {
    $p = new InMemoryRelayPauseStore();
    $p->hold('a', 'acme_*', null);
    $p->hold('b', 'acme_*', $this->at('2026-10-01 12:10:00'));

    $p->release('a');
    self::assertTrue($p->is_paused('acme_x', $this->at('2026-10-01 12:05:00')), 'b still holds');
    self::assertFalse($p->is_paused('acme_x', $this->at('2026-10-01 12:10:00')), 'b expired');
  }

  public function test_hold_is_one_row_per_holder_and_selector(): void {
    $p = new InMemoryRelayPauseStore();
    $p->hold('a', 'x', $this->at('2026-10-01 12:00:00'));
    $p->hold('a', 'x', null); // replaces, not stacks

    self::assertTrue($p->is_paused('x', $this->at('2030-01-01')));
    $p->release('a', 'x');
    self::assertFalse($p->is_paused('x', $this->at('2030-01-01')));
  }

  public function test_release_by_selector_leaves_the_holders_other_selectors(): void {
    $p = new InMemoryRelayPauseStore();
    $p->hold('a', 'x', null);
    $p->hold('a', 'y', null);

    $p->release('a', 'x');
    self::assertFalse($p->is_paused('x', $this->at('now')));
    self::assertTrue($p->is_paused('y', $this->at('now')));
  }
}
