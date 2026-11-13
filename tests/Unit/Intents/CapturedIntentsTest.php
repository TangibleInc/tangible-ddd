<?php

namespace TangibleDDD\Tests\Unit\Intents;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Correlation\TraceContext;
use TangibleDDD\Application\Intents\CapturedIntent;
use TangibleDDD\Application\Intents\CapturedIntents;
use TangibleDDD\Application\Intents\Decision;
use TangibleDDD\Application\Intents\IntentAlreadyDecided;
use TangibleDDD\IncorrectUsage;
use TangibleDDD\Tests\Fakes\FakeCapturingCommand as A;
use TangibleDDD\Tests\Fakes\Intents\FakeOtherCommand as B;

class CapturedIntentsTest extends TestCase {

  private CapturedIntent $a;
  private CapturedIntent $b;
  private CapturedIntents $intents;

  protected function setUp(): void {
    $this->a = new CapturedIntent(new A(1), TraceContext::root(), 'f.php:3');
    $this->b = new CapturedIntent(new B(), TraceContext::root(), 'g.php:9');
    $this->intents = new CapturedIntents([$this->a, $this->b]);
  }

  public function test_of_narrows_to_instances_of_the_class(): void {
    $subset = $this->intents->of(A::class);

    $this->assertCount(1, $subset);
    $this->assertSame($this->a, $subset->all()[0]);
  }

  public function test_deciding_the_subset_decides_the_intent(): void {
    $this->intents->of(A::class)->reject('x');

    $this->assertSame(Decision::rejected, $this->a->decision());
    $this->assertSame(Decision::undecided, $this->b->decision());
  }

  public function test_fulfil_returns_the_commands_and_marks_them(): void {
    $commands = $this->intents->of(A::class)->fulfil();

    $this->assertSame([$this->a->command], $commands);
    $this->assertSame(Decision::fulfilled, $this->a->decision());
  }

  public function test_reject_records_the_reason(): void {
    $this->intents->of(A::class)->reject('mine');

    $this->assertSame(
      ['command' => A::class, 'origin' => 'f.php:3', 'decision' => 'rejected', 'reason' => 'mine'],
      $this->a->to_record()
    );
  }

  public function test_deciding_twice_throws(): void {
    $subset = $this->intents->of(A::class);
    $subset->reject('a');

    $this->expectException(IntentAlreadyDecided::class);
    $subset->fulfil();
  }

  /** The decided intent is the set's last, so a per-intent check alone would decide $a first. */
  public function test_reject_decides_all_or_none(): void {
    $this->intents->of(B::class)->reject('mine');

    try {
      $this->intents->reject('x');
      $this->fail('Expected IntentAlreadyDecided');
    } catch( IntentAlreadyDecided $e ) {
      $this->assertSame(Decision::undecided, $this->a->decision());
    }
  }

  public function test_fulfil_decides_all_or_none(): void {
    $this->intents->of(B::class)->fulfil();

    try {
      $this->intents->fulfil();
      $this->fail('Expected IntentAlreadyDecided');
    } catch( IntentAlreadyDecided $e ) {
      $this->assertSame(Decision::undecided, $this->a->decision());
    }
  }

  public function test_undecided_keeps_only_undecided_intents(): void {
    $this->intents->of(B::class)->reject('done');

    $undecided = $this->intents->undecided();

    $this->assertSame([$this->a], $undecided->all());
  }

  public function test_undecided_then_reject_decides_the_originals(): void {
    $this->intents->of(B::class)->fulfil();

    $this->intents->undecided()->reject('rest');

    $this->assertSame(Decision::rejected, $this->a->decision());
    $this->assertSame(Decision::fulfilled, $this->b->decision());
  }

  public function test_where_applies_the_predicate(): void {
    $where = $this->intents->where(fn(CapturedIntent $i) => $i->command instanceof B);

    $this->assertSame([$this->b], $where->all());
  }

  public function test_where_returns_the_same_intents_not_copies(): void {
    $this->intents->where(fn() => true)->reject('x');

    $this->assertSame(Decision::rejected, $this->a->decision());
    $this->assertSame(Decision::rejected, $this->b->decision());
  }

  public function test_matching_compares_public_properties_strictly(): void {
    $other = new CapturedIntent(new A(2), TraceContext::root(), 'h.php:1');
    $set = new CapturedIntents([$this->a, $other]);

    $this->assertSame([$other], $set->matching(['request_id' => 2])->all());
    $this->assertCount(0, $set->matching(['request_id' => '2']));
  }

  public function test_matching_excludes_commands_without_the_property(): void {
    $matched = $this->intents->matching(['request_id' => 1]);

    $this->assertSame([$this->a], $matched->all());
    $this->assertCount(0, $this->intents->matching(['nope' => 1]));
  }

  public function test_matching_with_no_properties_keeps_everything(): void {
    $this->assertCount(2, $this->intents->matching([]));
  }

  public function test_filters_chain_and_decide_the_originals(): void {
    $this->intents->of(A::class)->matching(['request_id' => 1])->undecided()->reject('chained');

    $this->assertSame(Decision::rejected, $this->a->decision());
    $this->assertSame(Decision::undecided, $this->b->decision());
  }

  public function test_empty_results_are_empty_sets_and_rejecting_them_is_a_noop(): void {
    $empty = $this->intents->matching(['request_id' => 99]);

    $this->assertCount(0, $empty);
    $empty->reject('nothing');
    $this->assertSame([], $empty->fulfil());
    $this->assertSame(Decision::undecided, $this->a->decision());
  }

  public function test_empty_reason_is_incorrect_usage(): void {
    try {
      $this->intents->reject('  ');
      $this->fail('Expected IncorrectUsage');
    } catch( IncorrectUsage $e ) {
      $this->assertSame(Decision::undecided, $this->a->decision());
      $this->assertSame(Decision::undecided, $this->b->decision());
    }
  }
}
