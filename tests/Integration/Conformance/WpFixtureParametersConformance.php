<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Integration\Conformance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;
use TangibleDDD\Conformance\Fixtures\WidgetShipped;
use TangibleDDD\Conformance\HostFixture;

/**
 * The HostFixture parameters no shared scenario exercises yet, pinned on
 * the wp fixture so a future scenario cannot get wp-only answers:
 * relay_once($limit) claims at most $limit rows, and
 * deliver_transported($eventClass) runs only that class's actions. Not a
 * catalogue id; check-due ignores these methods.
 */
#[Group('wp')]
final class WpFixtureParametersConformance extends ConformanceTestCase {

  protected function create_fixture(): HostFixture {
    return new WpHostFixture();
  }

  #[TestDox('relay_once($limit) claims at most $limit due rows per step')]
  public function test_relay_once_honours_limit(): void {
    $ids = [
      $this->publish(new WidgetRegistered('w-1')),
      $this->publish(new WidgetRegistered('w-2')),
      $this->publish(new WidgetRegistered('w-3')),
    ];

    $first = $this->host->relay_once(2);
    self::assertCount(2, $first->claimed, 'a limit of 2 claims two rows');
    self::assertCount(2, $first->accepted);

    $second = $this->host->relay_once(2);
    self::assertCount(1, $second->claimed, 'the third row is left for the next step');

    $relayed = array_merge($first->accepted, $second->accepted);
    sort($relayed);
    sort($ids);
    self::assertSame($ids, $relayed);
  }

  #[TestDox('deliver_transported($eventClass) runs only the actions of that event class')]
  public function test_deliver_transported_filters_by_event_class(): void {
    $this->publish(new WidgetRegistered('w-1'));
    $this->host->seed_legacy_fact(new WidgetShipped('w-1'), 0, $this->host->clock()->now());
    self::assertCount(2, $this->host->relay_once()->accepted);
    self::assertCount(2, $this->host->transported());

    self::assertCount(1, $this->host->deliver_transported(WidgetShipped::class), 'only the WidgetShipped action');
    self::assertCount(1, $this->host->deliver_transported(WidgetRegistered::class), 'the WidgetRegistered action is still undelivered');
    self::assertSame([], $this->host->deliver_transported(WidgetRegistered::class), 'nothing left for that class');
    self::assertSame([], $this->host->deliver_transported(WidgetShipped::class));
  }
}
