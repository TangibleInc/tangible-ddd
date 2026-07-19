<?php

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;

use function TangibleDDD\WordPress\extract_correlation;

if (!function_exists('TangibleDDD\\WordPress\\extract_correlation')) {
  require_once __DIR__ . '/../../../ddd-wordpress/integration-events.php';
}

/**
 * The integration boundary: unwraps the OutboxProcessor transport envelope
 * and hands the payload to the handler. Scoping is the drain ceremony's
 * business (Correlation::within with the envelope's trace_context()) — the
 * unwrap itself must never touch ambient state.
 *
 * Backwards-compat contract: positional LIST payloads (every existing consumer)
 * spread as positional args exactly as before; ASSOCIATIVE payloads are now
 * preserved intact instead of being silently reindexed.
 */
class IntegrationBoundaryTest extends TestCase {

  protected function setUp(): void {
    \TangibleDDD\Application\Correlation\Correlation::reset();
  }

  protected function tearDown(): void {
    \TangibleDDD\Application\Correlation\Correlation::reset();
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

  public function test_unwrap_does_not_touch_the_ambient(): void {
    extract_correlation([[ '__correlation_id' => 'R-9', '__sequence' => '5', '__event_id' => 'evt-7', 'a' => 1 ]]);

    $this->assertNull(
      \TangibleDDD\Application\Correlation\Correlation::peek(),
      'stripping journey keys is not scoping — the ceremony owns the bracket'
    );
  }

  public function test_unwrapped_params_pass_through_untouched(): void {
    // No transport envelope → returned as-is (e.g. a plain WP action firing).
    $this->assertSame(['raw', 2], extract_correlation(['raw', 2]));
  }
}
