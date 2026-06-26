<?php

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;

use function TangibleDDD\WordPress\extract_correlation;

if (!function_exists('TangibleDDD\\WordPress\\extract_correlation')) {
  require_once __DIR__ . '/../../../ddd-wordpress/integration-events.php';
}

/**
 * The integration boundary: unwraps the OutboxProcessor transport envelope,
 * restores correlation/sequence, stamps causation from __event_id, and hands
 * the payload to the handler.
 *
 * Backwards-compat contract: positional LIST payloads (every existing consumer)
 * spread as positional args exactly as before; ASSOCIATIVE payloads are now
 * preserved intact instead of being silently reindexed.
 */
class IntegrationBoundaryTest extends TestCase {

  protected function setUp(): void {
    CorrelationContext::reset();
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  public function test_list_payload_spreads_positionally_backwards_compatible(): void {
    $args = extract_correlation([[
      '__correlation_id' => 'R',
      '__sequence'       => '3',
      '__event_id'       => 'e1',
      0 => 42,
      1 => 'x',
      2 => ['nested'],
    ]]);

    $this->assertSame([42, 'x', ['nested']], $args, 'list payload still spreads as positional args');
  }

  public function test_associative_payload_preserved_as_single_arg(): void {
    $args = extract_correlation([[
      '__correlation_id' => 'R',
      '__event_id'       => 'e1',
      'destination_id'   => 4,
      'reason'           => 'cutover',
    ]]);

    $this->assertSame(
      [['destination_id' => 4, 'reason' => 'cutover']],
      $args,
      'assoc payload passes through intact as one arg (no reindex)'
    );
  }

  public function test_boundary_stamps_causation_from_event_id(): void {
    extract_correlation([[ '__correlation_id' => 'R', '__event_id' => 'evt-7', 'a' => 1 ]]);

    $this->assertSame('evt-7', CorrelationContext::causation_id());
    $this->assertSame('integration_event', CorrelationContext::causation_type());
  }

  public function test_correlation_and_sequence_restored(): void {
    extract_correlation([[ '__correlation_id' => 'R-9', '__sequence' => '5', 'a' => 1 ]]);

    $this->assertSame('R-9', CorrelationContext::get());
    $this->assertSame(5, CorrelationContext::sequence());
  }

  public function test_unwrapped_params_pass_through_untouched(): void {
    // No transport envelope → returned as-is (e.g. a plain WP action firing).
    $this->assertSame(['raw', 2], extract_correlation(['raw', 2]));
  }
}
