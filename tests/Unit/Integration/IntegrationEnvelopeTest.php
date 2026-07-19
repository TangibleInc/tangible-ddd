<?php

namespace TangibleDDD\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\IntegrationEnvelope;

/**
 * The wire form of an integration event, complete: wrap() (formerly the
 * OutboxProcessor's private smear) and unwrap() are the two directions of
 * one codec, in one home. Only published events — facts — ever ride it.
 */
class IntegrationEnvelopeTest extends TestCase {

  protected function setUp(): void {
  }

  protected function tearDown(): void {
  }

  public function test_wrap_smears_the_journey_keys(): void {
    $wire = IntegrationEnvelope::wrap(['user_id' => 7], 'corr-1', 3, 'evt-9');

    $this->assertSame(
      ['user_id' => 7, '__correlation_id' => 'corr-1', '__sequence' => 3, '__event_id' => 'evt-9'],
      $wire,
    );
  }

  public function test_wrap_unwrap_round_trips(): void {
    $envelope = IntegrationEnvelope::unwrap(
      IntegrationEnvelope::wrap(['user_id' => 7, 'reason' => null], 'corr-1', 3, 'evt-9'),
    );

    $this->assertSame(['user_id' => 7, 'reason' => null], $envelope->payload);
    $this->assertSame('corr-1', $envelope->correlation_id);
    $this->assertSame(3, $envelope->sequence);
    $this->assertSame('evt-9', $envelope->event_id);
  }

  public function test_trace_context_exposes_the_journey_as_a_value(): void {
    $envelope = IntegrationEnvelope::unwrap(
      IntegrationEnvelope::wrap(['x' => 1], 'corr-tc', 7, 'evt-tc'),
    );

    $ctx = $envelope->trace_context();
    $this->assertSame('corr-tc', $ctx->correlation_id);
    $this->assertSame(7, $ctx->sequence);
    $this->assertNull($ctx->cause, 'the envelope carries the journey, not a cause — drains derive for_fact()');
  }

  public function test_trace_context_is_null_without_journey_keys(): void {
    $envelope = IntegrationEnvelope::unwrap(['plain' => 'args']);

    $this->assertNull($envelope->trace_context(), 'a bare hook call has no journey to scope');
  }
}
