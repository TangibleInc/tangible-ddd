<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;

class CorrelationContextTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  public function test_init_with_explicit_id(): void {
    CorrelationContext::init('my-corr-id');
    $this->assertSame('my-corr-id', CorrelationContext::get());
  }

  public function test_init_generates_id_when_null(): void {
    CorrelationContext::init();
    $id = CorrelationContext::get();
    $this->assertNotNull($id);
    $this->assertNotEmpty($id);
    // UUID v4 format: 8-4-4-4-12
    $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
  }

  public function test_get_auto_generates_if_not_set(): void {
    $id = CorrelationContext::get();
    $this->assertNotNull($id);
    // Calling again returns same ID
    $this->assertSame($id, CorrelationContext::get());
  }

  public function test_peek_returns_null_when_not_set(): void {
    $this->assertNull(CorrelationContext::peek());
  }

  public function test_peek_returns_id_when_set(): void {
    CorrelationContext::init('test-id');
    $this->assertSame('test-id', CorrelationContext::peek());
  }

  public function test_set_overrides_id(): void {
    CorrelationContext::init('first');
    CorrelationContext::set('second');
    $this->assertSame('second', CorrelationContext::get());
  }

  public function test_sequence_starts_at_zero(): void {
    $this->assertSame(0, CorrelationContext::sequence());
  }

  public function test_next_sequence_increments(): void {
    $this->assertSame(1, CorrelationContext::next_sequence());
    $this->assertSame(2, CorrelationContext::next_sequence());
    $this->assertSame(2, CorrelationContext::sequence()); // peek doesn't increment
  }

  public function test_set_sequence_restores_position(): void {
    CorrelationContext::set_sequence(10);
    $this->assertSame(10, CorrelationContext::sequence());
    $this->assertSame(11, CorrelationContext::next_sequence());
  }

  public function test_reset_clears_everything(): void {
    CorrelationContext::init('to-clear');
    CorrelationContext::next_sequence();

    CorrelationContext::reset();

    $this->assertNull(CorrelationContext::peek());
    $this->assertSame(0, CorrelationContext::sequence());
  }
}
