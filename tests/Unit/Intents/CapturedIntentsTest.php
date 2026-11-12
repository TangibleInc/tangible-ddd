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
