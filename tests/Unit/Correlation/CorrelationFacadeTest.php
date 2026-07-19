<?php

namespace TangibleDDD\Tests\Unit\Correlation;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\Correlation;
use TangibleDDD\Application\Correlation\Kind;
use TangibleDDD\Application\Correlation\TraceContext;

/**
 * The facade (0.3, spec §5): static ACCESS to immutable TraceContext values.
 * within() is the one bracket verb — push full snapshot (context AND the
 * sequence counter), run, restore on the way out, exceptions included.
 *
 * The per-scope counter snapshot is forced by the cross-story wake: a saga
 * from story A woken by story B's fact runs nested inside B's drain, and
 * each story's position must advance only while that story is current.
 */
class CorrelationFacadeTest extends TestCase {

  protected function setUp(): void {
    Correlation::reset();
  }

  protected function tearDown(): void {
    Correlation::reset();
  }

  public function test_flat_context_lazily_mints_a_root(): void {
    $ctx = Correlation::current();

    $this->assertNull($ctx->cause);
    $this->assertSame($ctx->correlation_id, Correlation::current()->correlation_id, 'stable once minted');
  }

  public function test_within_scopes_and_restores(): void {
    $outer = Correlation::current();
    $drain = $outer->for_fact('evt-1');

    $seen = Correlation::within($drain, static function () {
      return Correlation::current();
    });

    $this->assertSame('evt-1', $seen->cause->id);
    $this->assertSame(Kind::Fact, $seen->cause->kind);
    $this->assertSame($outer->correlation_id, Correlation::current()->correlation_id);
    $this->assertNull(Correlation::current()->cause, 'outer context restored whole');
  }

  public function test_within_restores_on_throw(): void {
    $outer = Correlation::current();

    try {
      Correlation::within($outer->for_act('cmd-1'), static function (): void {
        throw new \RuntimeException('step failed');
      });
      $this->fail('must rethrow');
    } catch (\RuntimeException) {
    }

    $this->assertNull(Correlation::current()->cause);
  }

  public function test_the_legal_stack_grammar_nests(): void {
    // drain → wake → act: [FACT, TRAJ, ACT], each restore exact.
    Correlation::within(Correlation::current()->for_fact('evt-1'), static function () {
      Correlation::within(Correlation::current()->for_trajectory('191'), static function () {
        Correlation::within(Correlation::current()->for_act('cmd-1'), static function () {
          TestCase::assertSame(Kind::Act, Correlation::current()->cause->kind);
        });
        TestCase::assertSame(Kind::Trajectory, Correlation::current()->cause->kind);
      });
      TestCase::assertSame(Kind::Fact, Correlation::current()->cause->kind);
    });
  }

  public function test_sequence_advances_within_the_current_story(): void {
    Correlation::within(new TraceContext('story-a', null, 3), static function () {
      TestCase::assertSame(4, Correlation::next_sequence());
      TestCase::assertSame(5, Correlation::next_sequence());
    });
  }

  public function test_cross_story_wake_snapshots_the_counter_per_scope(): void {
    // Story B's drain at position 3; story A's saga wakes nested inside it.
    Correlation::within(new TraceContext('story-b', null, 3), static function () {
      Correlation::next_sequence();                                   // B → 4

      Correlation::within(new TraceContext('story-a', null, 9), static function () {
        TestCase::assertSame('story-a', Correlation::current()->correlation_id);
        TestCase::assertSame(10, Correlation::next_sequence(), 'A advances from ITS position');
      });

      TestCase::assertSame('story-b', Correlation::current()->correlation_id);
      TestCase::assertSame(5, Correlation::next_sequence(), 'B resumes where B left off — A never corrupted it');
    });
  }
}
