<?php

namespace TangibleDDD\Tests\Unit\Integration;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\IntegrationEnvelope;

/**
 * The wire form of an integration event, complete: wrap() (formerly the
 * OutboxProcessor's private smear) and unwrap() are the two directions of
 * one codec, in one home. Only published events — facts — ever ride it.
 *
 * TransportEnvelope survives 0.2.x as a deprecated alias (rename is
 * additive, no consumer lockstep); dies in 0.3.
 */
class IntegrationEnvelopeTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
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

  public function test_transport_envelope_alias_survives_for_consumers(): void {
    $envelope = \TangibleDDD\Application\Events\TransportEnvelope::unwrap([
      'x' => 1, '__correlation_id' => 'c', '__sequence' => 1, '__event_id' => 'e',
    ]);

    $this->assertInstanceOf(IntegrationEnvelope::class, $envelope);
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

  public function test_restore_context_unchanged(): void {
    IntegrationEnvelope::unwrap(
      IntegrationEnvelope::wrap([], 'corr-r', 5, 'evt-r'),
    )->restore_context();

    $this->assertSame('corr-r', CorrelationContext::peek());
    $this->assertSame(5, CorrelationContext::sequence());
    $this->assertSame('evt-r', CorrelationContext::causation_id());
  }
}
