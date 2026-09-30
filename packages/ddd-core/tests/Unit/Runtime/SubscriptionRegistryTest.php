<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Core\Tests\Unit\Fixtures\BillingFact;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Core\Tests\Unit\Fixtures\UserJoined;
use TangibleDDD\Runtime\Delivery\ISubscriptionRegistry;
use TangibleDDD\Runtime\Delivery\Subscriber;
use TangibleDDD\Runtime\Delivery\SubscriptionRegistry;

final class SubscriptionRegistryTest extends TestCase {

  private function sub(string $id, int $priority, string $class): Subscriber {
    return new Subscriber($id, $priority, $class, static fn () => null);
  }

  public function test_for_orders_by_priority_then_registration_and_matches_markers(): void {
    $r = new SubscriptionRegistry();
    self::assertInstanceOf(ISubscriptionRegistry::class, $r);

    $r->add($this->sub('late', 100, BillingFact::class));
    $r->add($this->sub('resume', 99, OrderPlaced::class));
    $r->add($this->sub('other', 10, UserJoined::class));
    $r->add($this->sub('l2', 10, OrderPlaced::class));
    $r->add($this->sub('l1', 10, BillingFact::class));

    self::assertSame(
      ['l2', 'l1', 'resume', 'late'],
      array_map(static fn (Subscriber $s) => $s->id, $r->for(OrderPlaced::class))
    );
    self::assertSame(['other'], array_map(static fn (Subscriber $s) => $s->id, $r->for(UserJoined::class)));
  }

  public function test_a_duplicate_subscriber_id_is_ignored_first_wins(): void {
    $r = new SubscriptionRegistry();
    $r->add($this->sub('same', 10, OrderPlaced::class));
    $r->add($this->sub('same', 50, OrderPlaced::class));

    $subs = $r->for(OrderPlaced::class);
    self::assertCount(1, $subs);
    self::assertSame(10, $subs[0]->priority);
  }

  public function test_subscriber_rejects_an_empty_id(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->sub('', 10, OrderPlaced::class);
  }
}
