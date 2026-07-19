<?php

namespace TangibleDDD\Tests\Unit\WordPress;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Infrastructure\IInfrastructureEvent;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

use function TangibleDDD\WordPress\infrastructure_action;

if (!function_exists('TangibleDDD\\WordPress\\infrastructure_action')) {
  require_once __DIR__ . '/../../../ddd-wordpress/infrastructure-events.php';
}

/**
 * infrastructure_action() on the facade (0.4): the carried trace rides a
 * REAL Correlation::within() scope — correlation + the carried causation as
 * the ambient Cause (legacy dialect mapped back to Kind) — so a reaction
 * and any command it sends rejoin the originating trace. No legacy statics.
 */
class InfrastructureActionTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
    // Isolate the stub hook registry: callbacks registered by earlier tests
    // on the same action must not re-fire (their current() calls would mint
    // into the flat test's ambient).
    $GLOBALS['_test_actions'] = [];
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  private function event(?string $correlation, ?string $causation_id = null, ?string $causation_type = null): IInfrastructureEvent {
    return new class($correlation, $causation_id, $causation_type) implements IInfrastructureEvent {
      public function __construct(
        private ?string $correlation,
        private ?string $causation_id,
        private ?string $causation_type,
      ) {}
      public static function action(): string { return 'outbox_dlq'; }
      public function subject(): mixed { return null; }
      public function correlation_id(): ?string { return $this->correlation; }
      public function causation_id(): ?string { return $this->causation_id; }
      public function causation_type(): ?string { return $this->causation_type; }
    };
  }

  private function fire(IInfrastructureEvent $event, callable $callback): void {
    $config = new FakeDDDConfig();
    infrastructure_action($config, 'outbox_dlq', $callback);
    do_action($config->hook('outbox_dlq'), $event);
  }

  public function test_reaction_runs_inside_the_carried_trace(): void {
    $seen = null;
    $this->fire(
      $this->event('story-x', 'evt-9', 'integration_event'),
      static function () use (&$seen) { $seen = Correlation::current(); }
    );

    $this->assertInstanceOf(TraceContext::class, $seen);
    $this->assertSame('story-x', $seen->correlation_id);
    $this->assertSame(Kind::Fact, $seen->cause->kind, 'legacy dialect maps back to the Kind');
    $this->assertSame('evt-9', $seen->cause->id);

    $this->assertNull(Correlation::peek(), 'scope restored — nothing bleeds into the worker');
  }

  public function test_saga_causation_maps_to_trajectory(): void {
    $seen = null;
    $this->fire(
      $this->event('story-x', '191', 'long_process'),
      static function () use (&$seen) { $seen = Correlation::current()->cause; }
    );

    $this->assertSame(Kind::Trajectory, $seen->kind);
    $this->assertSame('191', $seen->id);
  }

  public function test_correlation_without_causation_scopes_causeless(): void {
    $seen = false;
    $this->fire(
      $this->event('story-y'),
      static function () use (&$seen) { $seen = Correlation::peek(); }
    );

    $this->assertSame('story-y', $seen->correlation_id);
    $this->assertNull($seen->cause);
  }

  public function test_ambient_event_runs_flat(): void {
    $inside = 'unset';
    $this->fire(
      $this->event(null, 'evt-9', 'integration_event'),
      static function () use (&$inside) { $inside = Correlation::peek(); }
    );

    $this->assertNull($inside, 'no carried story — no scope to open (causation alone cannot anchor one)');
  }
}
