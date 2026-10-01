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
 * relayOnce($limit) claims at most $limit rows, and
 * deliverTransported($eventClass) runs only that class's actions. Not a
 * catalogue id; check-due ignores these methods.
 */
#[Group('wp')]
final class WpFixtureParametersConformance extends ConformanceTestCase {

  protected function createFixture(): HostFixture {
    return new WpHostFixture();
  }

  #[TestDox('relayOnce($limit) claims at most $limit due rows per step')]
  public function test_relay_once_honours_limit(): void {
    $ids = [
      $this->publishFact(new WidgetRegistered('w-1')),
      $this->publishFact(new WidgetRegistered('w-2')),
      $this->publishFact(new WidgetRegistered('w-3')),
    ];

    $first = $this->host->relayOnce(2);
    self::assertCount(2, $first->claimed, 'a limit of 2 claims two rows');
    self::assertCount(2, $first->accepted);

    $second = $this->host->relayOnce(2);
    self::assertCount(1, $second->claimed, 'the third row is left for the next step');

    $relayed = array_merge($first->accepted, $second->accepted);
    sort($relayed);
    sort($ids);
    self::assertSame($ids, $relayed);
  }

  #[TestDox('deliverTransported($eventClass) runs only the actions of that event class')]
  public function test_deliver_transported_filters_by_event_class(): void {
    $this->publishFact(new WidgetRegistered('w-1'));
    $this->host->seedLegacyDelayedFact(new WidgetShipped('w-1'), 0, $this->host->clock()->now());
    self::assertCount(2, $this->host->relayOnce()->accepted);
    self::assertCount(2, $this->host->transported());

    self::assertCount(1, $this->host->deliverTransported(WidgetShipped::class), 'only the WidgetShipped action');
    self::assertCount(1, $this->host->deliverTransported(WidgetRegistered::class), 'the WidgetRegistered action is still undelivered');
    self::assertSame([], $this->host->deliverTransported(WidgetRegistered::class), 'nothing left for that class');
    self::assertSame([], $this->host->deliverTransported(WidgetShipped::class));
  }
}
