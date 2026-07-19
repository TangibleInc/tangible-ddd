<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Cause;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;

/**
 * The noun that was missing (0.3, spec §4): TraceContext is the immutable
 * propagated value {correlation_id, ?Cause, sequence}. Cause is (id, kind,
 * diagnostics-only label) — the node currently executing, whose id is
 * always BORROWED (command_id / event_id / process id), never minted.
 *
 * Derivations return a copy with ONLY the cause swapped: same story, same
 * position, new "you are here" marker.
 */
class TraceContextTest extends TestCase {

  public function test_kind_is_the_closed_three(): void {
    $this->assertSame(
      ['act', 'fact', 'trajectory'],
      array_map(static fn (Kind $k) => $k->value, Kind::cases()),
    );
  }

  public function test_root_mints_a_fresh_story(): void {
    $a = TraceContext::root();
    $b = TraceContext::root();

    $this->assertNull($a->cause, 'a new story has no enclosing node');
    $this->assertSame(0, $a->sequence);
    $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $a->correlation_id);
    $this->assertNotSame($a->correlation_id, $b->correlation_id, 'coordination-free minting');
  }

  public function test_derivations_swap_only_the_cause(): void {
    $ctx = new TraceContext('corr-1', null, 7);

    $act = $ctx->for_act('cmd-1', 'Acme\\ShipWidget');
    $this->assertSame('corr-1', $act->correlation_id);
    $this->assertSame(7, $act->sequence);
    $this->assertSame('cmd-1', $act->cause->id);
    $this->assertSame(Kind::Act, $act->cause->kind);
    $this->assertSame('Acme\\ShipWidget', $act->cause->label);

    $fact = $ctx->for_fact('evt-1');
    $this->assertSame(Kind::Fact, $fact->cause->kind);
    $this->assertNull($fact->cause->label, 'label is optional diagnostics');

    $traj = $ctx->for_trajectory('191', 'Acme\\SomeSaga');
    $this->assertSame(Kind::Trajectory, $traj->cause->kind);
    $this->assertSame('191', $traj->cause->id);

    $this->assertNull($ctx->cause, 'derivation never mutates the source');
  }

  public function test_derivation_from_a_caused_context_replaces_the_cause(): void {
    $drain = (new TraceContext('corr-1'))->for_fact('evt-1');
    $act = $drain->for_act('cmd-9');

    $this->assertSame(Kind::Act, $act->cause->kind);
    $this->assertSame('cmd-9', $act->cause->id);
    $this->assertSame(Kind::Fact, $drain->cause->kind, 'source untouched');
  }

  public function test_cause_maps_kind_to_the_legacy_column_dialect(): void {
    // Ruling: columns keep 'integration_event'/'long_process' forever;
    // Kind translates at the projection boundary.
    $this->assertSame('integration_event', (new Cause('e', Kind::Fact))->causation_type());
    $this->assertSame('long_process', (new Cause('p', Kind::Trajectory))->causation_type());
    $this->assertSame('command', (new Cause('c', Kind::Act))->causation_type());
  }
}
