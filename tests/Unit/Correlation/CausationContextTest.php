<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;

/**
 * Causation is the parent edge (one hop up): which event or process directly
 * caused the next command. Distinct from correlation (the whole-trace root) and
 * from command_id (the node's own id). By doctrine the parent is one of two
 * coordination modes — integration_event (choreography) or long_process
 * (orchestration); roots have no causation.
 */
class CausationContextTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  public function test_causation_defaults_to_null(): void {
    $this->assertNull(CorrelationContext::causation_id());
    $this->assertNull(CorrelationContext::causation_type());
  }

  public function test_choreography_causation_is_an_integration_event(): void {
    CorrelationContext::set_causation('evt-9af1', 'integration_event');

    $this->assertSame('evt-9af1', CorrelationContext::causation_id());
    $this->assertSame('integration_event', CorrelationContext::causation_type());
  }

  public function test_orchestration_causation_is_a_long_process(): void {
    CorrelationContext::set_causation('5582', 'long_process');

    $this->assertSame('5582', CorrelationContext::causation_id());
    $this->assertSame('long_process', CorrelationContext::causation_type());
  }

  public function test_clear_consumes_causation(): void {
    CorrelationContext::set_causation('evt-1', 'integration_event');
    CorrelationContext::clear_causation();

    $this->assertNull(CorrelationContext::causation_id(), 'id consumed');
    $this->assertNull(CorrelationContext::causation_type(), 'type consumed');
  }

  public function test_reset_clears_causation(): void {
    CorrelationContext::set_causation('evt-1', 'integration_event');
    CorrelationContext::reset();

    $this->assertNull(CorrelationContext::causation_id());
    $this->assertNull(CorrelationContext::causation_type());
  }

  /**
   * Causation and correlation are orthogonal axes — setting the parent edge
   * must not disturb the trace id, and vice versa. A workflow tick has both:
   * caused by the reschedule event, belonging to the original trace.
   */
  public function test_causation_is_independent_of_correlation(): void {
    CorrelationContext::init('trace-R1');
    CorrelationContext::set_causation('evt-parent', 'integration_event');

    $this->assertSame('trace-R1', CorrelationContext::get(), 'trace id untouched');
    $this->assertSame('evt-parent', CorrelationContext::causation_id(), 'parent edge set');

    CorrelationContext::clear_causation();
    $this->assertSame('trace-R1', CorrelationContext::get(), 'clearing causation leaves trace intact');
  }

  /**
   * The consume contract that prevents bleed: after a command records its
   * causation (clear), a subsequent command with no causer reads null — it is
   * NOT falsely attributed to the previous command's parent.
   */
  public function test_consumed_causation_does_not_bleed_to_next_command(): void {
    CorrelationContext::set_causation('evt-A', 'integration_event');
    // first command records + consumes
    $first_id = CorrelationContext::causation_id();
    CorrelationContext::clear_causation();
    // second command, dispatched by nothing (a root) — must see null
    $second_id = CorrelationContext::causation_id();

    $this->assertSame('evt-A', $first_id);
    $this->assertNull($second_id, 'next command must not inherit the prior parent');
  }
}
