<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Application\Logging\CommandAuditMiddleware;
use TangibleDDD\Application\Logging\Redactor;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;
use TangibleDDD\Tests\Fakes\FakeOutcome;

// Procedural ddd-wordpress ceremony functions (mirrors BootTest).
if (!function_exists('TangibleDDD\\WordPress\\integration_action')) {
  require_once __DIR__ . '/../../../ddd-wordpress/integration-events.php';
}

/**
 * Causation is a SCOPE, not a one-shot token (0.2.5a — the fat-listener fix).
 *
 * The drain arms the event as causation for its WHOLE body: a listener that
 * dispatches three commands gives all three `causation = this event`. The
 * audit middleware only READS; teardown belongs to the drain ceremony that
 * armed it (so nothing bleeds into the worker's next action).
 *
 * Old semantics (arm → first command consumes → siblings get null) recorded
 * silently wrong data: commands 2..n of a fat listener showed up as roots.
 */
class ScopedCausationTest extends TestCase {

  /** @var array<int, array> captured audit insert rows */
  private array $inserts = [];

  protected function setUp(): void {
    global $_test_actions, $_test_filters;
    $_test_actions = [];
    $_test_filters = [];
    CorrelationContext::reset();
    $this->inserts = [];
  }

  protected function tearDown(): void {
    CorrelationContext::reset();
  }

  private function make_config(string $prefix): IDDDConfig {
    return new class($prefix) implements IDDDConfig {
      public function __construct(private string $p) {}
      public function prefix(): string { return $this->p; }
      public function table(string $name): string { return 'wp_' . $this->p . '_' . $name; }
      public function hook(string $name): string { return $this->p . '_' . $name; }
      public function as_group(string $name): string { return $this->p . '-' . $name; }
      public function option(string $name): string { return $this->p . '_' . $name; }
      public function domain_action(string $e): string { return $this->p . '_domain_' . $e; }
      public function integration_action(string $e): string { return $this->p . '_integration_' . $e; }
      public function version(): string { return $this->p; }
    };
  }

  /** Audit-enabled wpdb whose insert() captures rows into $this->inserts. */
  private function capture_wpdb(string $audit_table): \wpdb {
    $wpdb = $this->createMock(\wpdb::class);
    $wpdb->method('get_var')->willReturn($audit_table);
    $wpdb->method('prepare')->willReturnArgument(0);
    $wpdb->method('update')->willReturn(true);
    $wpdb->method('insert')->willReturnCallback(function ($table, $row) {
      $this->inserts[] = $row;
      return true;
    });
    return $wpdb;
  }

  private function make_middleware(string $prefix): CommandAuditMiddleware {
    $config = $this->make_config($prefix);
    $GLOBALS['wpdb'] = $this->capture_wpdb($config->table('command_audit'));
    return new CommandAuditMiddleware($config, new EventsUnitOfWork(), new Redactor());
  }

  public function test_sibling_commands_share_the_armed_causation(): void {
    $middleware = $this->make_middleware('scopedcaus1');
    CorrelationContext::init('corr-scope');
    CorrelationContext::set_causation('evt-fat', 'integration_event');

    $middleware->execute(new \stdClass(), fn () => 'one');
    $middleware->execute(new \stdClass(), fn () => 'two');

    $this->assertCount(2, $this->inserts);
    $this->assertSame('evt-fat', $this->inserts[0]['causation_id'], 'first command records the event');
    $this->assertSame('evt-fat', $this->inserts[1]['causation_id'], 'sibling must NOT record a false root');
    $this->assertSame('integration_event', $this->inserts[1]['causation_type']);
  }

  public function test_audit_middleware_no_longer_consumes(): void {
    $middleware = $this->make_middleware('scopedcaus2');
    CorrelationContext::init('corr-scope');
    CorrelationContext::set_causation('evt-1', 'integration_event');

    $middleware->execute(new \stdClass(), fn () => 'ok');

    $this->assertSame('evt-1', CorrelationContext::causation_id(), 'reading is not consuming');
  }

  public function test_integration_action_ceremony_clears_causation_at_teardown(): void {
    \TangibleDDD\WordPress\integration_action(
      FakeResolvedEvent::class,
      function () {
        $this->assertSame('evt-drain', CorrelationContext::causation_id(), 'armed for the whole body');
      },
    );

    do_action(FakeResolvedEvent::integration_action(), $this->wrapped_payload('evt-drain'));

    $this->assertNull(CorrelationContext::causation_id(), 'drain teardown must clear — no bleed into the next action');
  }

  public function test_integration_listener_ceremony_clears_causation_at_teardown(): void {
    \TangibleDDD\WordPress\integration_listener(
      FakeResolvedEvent::class,
      function ($event) {
        $this->assertSame('evt-drain2', CorrelationContext::causation_id());
        return null;
      },
    );

    do_action(FakeResolvedEvent::integration_action(), $this->wrapped_payload('evt-drain2'));

    $this->assertNull(CorrelationContext::causation_id());
  }

  private function wrapped_payload(string $event_id): array {
    return [
      'request_id' => 7,
      'outcome' => FakeOutcome::Accepted->value,
      'resolved_at' => '2026-07-19T00:00:00+00:00',
      'extra' => [],
      '__correlation_id' => 'corr-drain',
      '__sequence' => 3,
      '__event_id' => $event_id,
    ];
  }
}
