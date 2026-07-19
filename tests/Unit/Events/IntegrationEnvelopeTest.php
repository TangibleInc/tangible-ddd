<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\IntegrationEnvelope;

class IntegrationEnvelopeTest extends TestCase {

  protected function setUp(): void { CorrelationContext::reset(); }
  protected function tearDown(): void { CorrelationContext::reset(); }

  public function test_unwrap_separates_journey_from_fact(): void {
    $env = IntegrationEnvelope::unwrap([
      'request_id' => 312, 'outcome' => 'accepted',
      '__correlation_id' => 'corr-1', '__sequence' => 3, '__event_id' => 'ev-9',
    ]);
    $this->assertSame(['request_id' => 312, 'outcome' => 'accepted'], $env->payload);
    $this->assertSame('corr-1', $env->correlation_id);
    $this->assertSame(3, $env->sequence);
    $this->assertSame('ev-9', $env->event_id);
  }

  public function test_unwrap_without_transport_keys(): void {
    $env = IntegrationEnvelope::unwrap(['a' => 1]);
    $this->assertSame(['a' => 1], $env->payload);
    $this->assertNull($env->correlation_id);
    $this->assertNull($env->event_id);
  }

  public function test_restore_context_inits_correlation_and_causation(): void {
    $env = IntegrationEnvelope::unwrap(['a' => 1, '__correlation_id' => 'corr-1', '__event_id' => 'ev-9']);
    $env->restore_context();
    $this->assertSame('corr-1', CorrelationContext::peek());
  }
}
