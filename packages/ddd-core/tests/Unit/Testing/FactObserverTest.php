<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Core\Tests\Unit\Fixtures\OrderPlaced;
use TangibleDDD\Runtime\IFactObserver;
use TangibleDDD\Runtime\NullFactObserver;
use TangibleDDD\Runtime\Outbox\OutboxRecord;
use TangibleDDD\Testing\RecordingFactObserver;

final class FactObserverTest extends TestCase {

  private function record(): OutboxRecord {
    return new OutboxRecord('e1', 'order_placed', OrderPlaced::integration_action(), null, null, null, [], new \DateTimeImmutable());
  }

  public function test_null_observer_does_nothing(): void {
    $o = new NullFactObserver();
    self::assertInstanceOf(IFactObserver::class, $o);
    $o->observe(new OrderPlaced(), $this->record());
    $this->addToAssertionCount(1);
  }

  public function test_recording_observer_records_and_can_throw(): void {
    $o = new RecordingFactObserver();
    $o->observe(new OrderPlaced(9), $r = $this->record());
    self::assertSame(9, $o->observed[0]['event']->order_id);
    self::assertSame($r, $o->observed[0]['record']);

    $boom = new RecordingFactObserver(new \RuntimeException('indexer down'));
    $this->expectExceptionMessage('indexer down');
    $boom->observe(new OrderPlaced(), $this->record());
  }

  public function test_fixture_action_names_resolve_without_wordpress(): void {
    self::assertSame('acme_integration_order_placed', OrderPlaced::integration_action());
  }
}
