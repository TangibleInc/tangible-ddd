<?php

declare(strict_types=1);

namespace TangibleDDD\Conformance\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\IntegrationEnvelope;
use TangibleDDD\Conformance\ConformanceTestCase;
use TangibleDDD\Conformance\Fixtures\WidgetRegistered;

/** W3-WPC3-2: hand-built envelopes carry a UUID correlation id by default, like relayed ones. */
#[Group('mem')]
final class WrapTest extends TestCase {

  public function test_the_default_correlation_id_is_a_deterministic_uuid(): void {
    $wrap = new \ReflectionMethod(ConformanceTestCase::class, 'wrap');
    $a = IntegrationEnvelope::unwrap($wrap->invoke(null, new WidgetRegistered('w-1'), 'e-1'));
    $b = IntegrationEnvelope::unwrap($wrap->invoke(null, new WidgetRegistered('w-1'), 'e-1'));
    $c = IntegrationEnvelope::unwrap($wrap->invoke(null, new WidgetRegistered('w-1'), 'e-2'));

    self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $a->correlation_id);
    self::assertSame(36, strlen((string) $a->correlation_id), 'fits a CHAR(36) column (wp long_processes.correlation_id)');
    self::assertSame($a->correlation_id, $b->correlation_id, 'deterministic per event id');
    self::assertNotSame($a->correlation_id, $c->correlation_id);
  }

  public function test_an_explicit_correlation_id_is_kept(): void {
    $wrap = new \ReflectionMethod(ConformanceTestCase::class, 'wrap');
    self::assertSame('corr-x', IntegrationEnvelope::unwrap($wrap->invoke(null, new WidgetRegistered('w-1'), 'e-1', 'corr-x'))->correlation_id);
  }
}
