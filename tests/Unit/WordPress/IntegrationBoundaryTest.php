<?php

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

use function TangibleDDD\WordPress\integration_action;

if (!function_exists('TangibleDDD\\WordPress\\integration_action')) {
  require_once __DIR__ . '/../../../ddd-wordpress/integration-events.php';
}

/**
 * The integration boundary: the ceremony unwraps the OutboxProcessor
 * transport envelope and hands the payload to the handler inside the fact's
 * trace scope.
 *
 * Payload contract: positional LIST payloads (every early consumer) spread
 * as positional args exactly as before; ASSOCIATIVE payloads are preserved
 * intact as a single arg (never silently reindexed).
 */
class IntegrationBoundaryTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
    $GLOBALS['_test_actions'] = [];
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  /** Register a capture callback and fire the wrapped payload through the hook. */
  private function fire(array ...$args): array {
    $seen = null;
    integration_action(FakeResolvedEvent::class, function (...$params) use (&$seen) {
      $seen = $params;
    }, 10, 3);

    do_action(FakeResolvedEvent::integration_action(), ...$args);

    return $seen;
  }

  public function test_list_payload_spreads_positionally_backwards_compatible(): void {
    $args = $this->fire([
      '__correlation_id' => 'R',
      '__sequence'       => '3',
      '__event_id'       => 'e1',
      0 => 42,
      1 => 'x',
      2 => ['nested'],
    ]);

    $this->assertSame([42, 'x', ['nested']], $args, 'list payload still spreads as positional args');
  }

  public function test_associative_payload_preserved_as_single_arg(): void {
    $args = $this->fire([
      '__correlation_id' => 'R',
      '__event_id'       => 'e1',
      'destination_id'   => 4,
      'reason'           => 'cutover',
    ]);

    $this->assertSame(
      [['destination_id' => 4, 'reason' => 'cutover']],
      $args,
      'assoc payload passes through intact as one arg (no reindex)'
    );
  }

  public function test_bare_params_pass_through_untouched(): void {
    // No transport envelope → handed over as-is (a plain WP action firing).
    $this->assertSame([['raw' => 2]], $this->fire(['raw' => 2]));
  }

  public function test_unwrap_does_not_leak_ambient_state(): void {
    $this->fire([
      '__correlation_id' => 'R-9', '__sequence' => '5', '__event_id' => 'evt-7', 'a' => 1,
    ]);

    $this->assertNull(
      Correlation::peek(),
      'the drain scope closes with the callback — nothing bleeds into the worker'
    );
  }
}
