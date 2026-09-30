<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Runtime\FrozenClock;
use TangibleDDD\Runtime\IClock;
use TangibleDDD\Runtime\SystemClock;
use TangibleDDD\Testing\EnvOffsetClock;

final class ClockTest extends TestCase {

  protected function tearDown(): void {
    putenv('DDD_CLOCK_OFFSET');
  }

  public function test_system_clock_is_utc_and_current(): void {
    $clock = new SystemClock();
    $now = $clock->now();

    self::assertInstanceOf(IClock::class, $clock);
    self::assertSame('UTC', $now->getTimezone()->getName());
    self::assertEqualsWithDelta(time(), $now->getTimestamp(), 2);
  }

  public function test_frozen_clock_holds_still_until_advanced(): void {
    $clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 12:00:00', new \DateTimeZone('UTC')));

    self::assertSame('2026-10-01T12:00:00+00:00', $clock->now()->format(DATE_ATOM));
    self::assertSame('2026-10-01T12:00:00+00:00', $clock->now()->format(DATE_ATOM));

    $clock->advance('PT25H');
    self::assertSame('2026-10-02T13:00:00+00:00', $clock->now()->format(DATE_ATOM));

    $clock->advance('90 seconds');
    self::assertSame('2026-10-02T13:01:30+00:00', $clock->now()->format(DATE_ATOM));
  }

  public function test_frozen_clock_normalises_a_non_utc_start_to_utc(): void {
    $clock = new FrozenClock(new \DateTimeImmutable('2026-10-01 14:00:00', new \DateTimeZone('Europe/Berlin')));

    self::assertSame('UTC', $clock->now()->getTimezone()->getName());
    self::assertSame('2026-10-01T12:00:00+00:00', $clock->now()->format(DATE_ATOM));
  }

  public function test_frozen_clock_can_be_set(): void {
    $clock = new FrozenClock();
    $clock->set(new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC')));

    self::assertSame('2030-01-01T00:00:00+00:00', $clock->now()->format(DATE_ATOM));
  }

  public function test_frozen_clock_rejects_an_unparseable_interval(): void {
    $this->expectException(\InvalidArgumentException::class);
    (new FrozenClock())->advance('not an interval');
  }

  public function test_env_offset_clock_without_offset_is_system_time(): void {
    putenv('DDD_CLOCK_OFFSET');
    $now = (new EnvOffsetClock())->now();

    self::assertSame('UTC', $now->getTimezone()->getName());
    self::assertEqualsWithDelta(time(), $now->getTimestamp(), 2);
  }

  public function test_env_offset_clock_adds_seconds(): void {
    putenv('DDD_CLOCK_OFFSET=3600');
    self::assertEqualsWithDelta(time() + 3600, (new EnvOffsetClock())->now()->getTimestamp(), 2);
  }

  public function test_env_offset_clock_adds_an_iso8601_duration(): void {
    putenv('DDD_CLOCK_OFFSET=P1DT1H');
    self::assertEqualsWithDelta(time() + 90000, (new EnvOffsetClock())->now()->getTimestamp(), 2);
  }

  public function test_env_offset_clock_accepts_a_negative_seconds_offset(): void {
    putenv('DDD_CLOCK_OFFSET=-60');
    self::assertEqualsWithDelta(time() - 60, (new EnvOffsetClock())->now()->getTimestamp(), 2);
  }

  public function test_env_offset_clock_rejects_garbage(): void {
    putenv('DDD_CLOCK_OFFSET=tomorrow-ish');
    $this->expectException(\InvalidArgumentException::class);
    (new EnvOffsetClock())->now();
  }
}
