<?php

declare(strict_types=1);

namespace TangibleDDD\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\FactRef;

final class FactRefTest extends TestCase {

  public function test_fact_ref_carries_the_delivered_fact_identity(): void {
    $ref = new FactRef('0192f5d2-7c1e-7000-8000-000000000001', 'App\\Events\\OrderPlaced', 'corr-1');

    self::assertSame('0192f5d2-7c1e-7000-8000-000000000001', $ref->event_id);
    self::assertSame('App\\Events\\OrderPlaced', $ref->event_class);
    self::assertSame('corr-1', $ref->correlation_id);
  }

  public function test_fact_ref_is_an_immutable_value(): void {
    $class = new \ReflectionClass(FactRef::class);

    self::assertTrue($class->isFinal());
    foreach ($class->getProperties() as $property) {
      self::assertTrue($property->isReadOnly(), $property->getName() . ' must be readonly');
    }
  }
}
