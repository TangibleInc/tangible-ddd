<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\CorrelationMiddleware;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Infra\DDDConfig;
use TangibleDDD\Tests\Fakes\FakeOutcome;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

// Procedural ceremony functions (mirrors ScopedCausationTest).
if (!function_exists('TangibleDDD\\WordPress\\integration_action')) {
  require_once __DIR__ . '/../../../ddd-wordpress/integration-events.php';
}

/**
 * The drain bracket (0.3 lane 2, spec §6.1): every drain ceremony opens a
 * REAL facade scope — Correlation::within($envelope->trace_context()
 * ->for_fact($event_id), $body) — so the fact is the ambient cause for the
 * WHOLE body by scope semantics, not by armed-slot choreography.
 */
class DrainBracketTest extends TestCase {

  /** @var array<int, array> */
  private array $inserts = [];

  protected function setUp(): void {
    global $_test_actions, $_test_filters;
    $_test_actions = [];
    $_test_filters = [];
    Correlation::reset();
    $this->inserts = [];
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  private function make_bracket(string $prefix): CorrelationMiddleware {
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('get_var')->willReturn('wp_' . $prefix . '_command_audit');
    $wpdb->method('prepare')->willReturnArgument(0);
    $wpdb->method('update')->willReturn(true);
    $wpdb->method('insert')->willReturnCallback(function ($table, $row) {
      $this->inserts[] = $row;
      return true;
    });
    $GLOBALS['wpdb'] = $wpdb;

    return new CorrelationMiddleware(
      new DDDConfig(prefix: $prefix, namespace_root: 'Drain\\Tests', version: 't'),
      new EventsUnitOfWork(),
      new Redactor(),
    );
  }

  private function wrapped_payload(string $event_id, string $correlation = 'corr-drain'): array {
    return [
      'request_id' => 7,
      'outcome' => FakeOutcome::Accepted->value,
      'resolved_at' => '2026-07-19T00:00:00+00:00',
      'extra' => [],
      '__correlation_id' => $correlation,
      '__sequence' => 3,
      '__event_id' => $event_id,
    ];
  }

  public function test_integration_action_opens_a_fact_scope(): void {
    $seen = null;
    \TangibleDDD\WordPress\integration_action(FakeResolvedEvent::class, function () use (&$seen) {
      $seen = Correlation::current();
    });

    do_action(FakeResolvedEvent::integration_action(), $this->wrapped_payload('evt-d1'));

    $this->assertSame('corr-drain', $seen->correlation_id);
    $this->assertSame(Kind::Fact, $seen->cause->kind);
    $this->assertSame('evt-d1', $seen->cause->id);
    $this->assertSame(3, $seen->sequence);
    $this->assertNull(Correlation::peek(), 'facade scope closed at teardown');
  }

  public function test_integration_listener_opens_a_fact_scope(): void {
    $seen = null;
    \TangibleDDD\WordPress\integration_listener(FakeResolvedEvent::class, function ($event) use (&$seen) {
      $seen = Correlation::current();
      return null;
    });

    do_action(FakeResolvedEvent::integration_action(), $this->wrapped_payload('evt-d2'));

    $this->assertSame(Kind::Fact, $seen->cause->kind);
    $this->assertSame('evt-d2', $seen->cause->id);
    $this->assertNull(Correlation::peek());
  }

  public function test_fat_listener_siblings_parent_correctly_through_real_chains(): void {
    // THE composed-chain case the 0.2.5 bug hid: two commands in one drain,
    // each through the real act bracket — both must record the fact.
    $bracket = $this->make_bracket('drainb3');

    \TangibleDDD\WordPress\integration_action(FakeResolvedEvent::class, function () use ($bracket) {
      $bracket->execute(new \stdClass(), static fn () => 'one');
      $bracket->execute(new \stdClass(), static fn () => 'two');
    });

    do_action(FakeResolvedEvent::integration_action(), $this->wrapped_payload('evt-fat', 'corr-fat'));

    $this->assertCount(2, $this->inserts);
    foreach ($this->inserts as $i => $row) {
      $this->assertSame('corr-fat', $row['correlation_id'], "command $i shares the drain story");
      $this->assertSame('evt-fat', $row['causation_id'], "command $i parents on the fact");
      $this->assertSame('integration_event', $row['causation_type']);
    }
  }

  public function test_bare_hook_calls_run_unscoped(): void {
    $seen = 'untouched';
    \TangibleDDD\WordPress\integration_action(FakeResolvedEvent::class, function () use (&$seen) {
      $seen = Correlation::peek();
    });

    do_action(FakeResolvedEvent::integration_action(), ['request_id' => 1]);

    $this->assertNull($seen, 'no journey keys → no scope to open');
  }
}
