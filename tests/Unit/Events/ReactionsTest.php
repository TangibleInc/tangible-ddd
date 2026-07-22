<?php

namespace TangibleDDD\Tests\Unit\Events;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Events\Reactions;
use TangibleDDD\Infra\Services\WordPressEventDispatcher;
use TangibleDDD\Tests\Fakes\FakeDomainEvent;
use TangibleDDD\Tests\Fakes\FakeReactingHandler;

/**
 * The reactions ledger: which handlers fired for each published moment.
 * Attribution is POSITIONAL (the dispatch stack), never keyed on the
 * handler-side instance — WordPressActionHandler's closure reconstructs
 * the event from the action args, so the instance the handler holds is
 * NOT the instance the dispatcher published.
 */
class ReactionsTest extends TestCase {

  protected function setUp(): void {
    Reactions::reset();
    global $_test_actions;
    $_test_actions = [];
    FakeReactingHandler::$throw = null;
    FakeReactingHandler::$handled = [];
  }

  protected function tearDown(): void {
    Reactions::reset();
  }

  // ── the whiteboard itself ──

  public function test_record_inside_open_frame_attributes_to_that_instance(): void {
    $event = new FakeDomainEvent(1);

    Reactions::open($event);
    Reactions::record('Acme\\Handlers\\SendWelcome', 12);
    Reactions::close();

    $this->assertSame(
      [['handler' => 'Acme\\Handlers\\SendWelcome', 'duration_ms' => 12]],
      Reactions::of($event)
    );
  }

  public function test_record_with_empty_stack_is_a_silent_noop(): void {
    $event = new FakeDomainEvent(1);

    Reactions::record('Acme\\Handlers\\SendWelcome', 12);

    $this->assertSame([], Reactions::of($event));
  }

  public function test_nested_frames_attribute_to_the_innermost_event(): void {
    $outer = new FakeDomainEvent(1);
    $inner = new FakeDomainEvent(2);

    Reactions::open($outer);
    Reactions::record('OuterHandler', 1);
    Reactions::open($inner);
    Reactions::record('InnerHandler', 2);
    Reactions::close();
    Reactions::record('OuterHandlerAgain', 3);
    Reactions::close();

    $this->assertSame(
      [['handler' => 'InnerHandler', 'duration_ms' => 2]],
      Reactions::of($inner)
    );
    $this->assertSame(
      [
        ['handler' => 'OuterHandler', 'duration_ms' => 1],
        ['handler' => 'OuterHandlerAgain', 'duration_ms' => 3],
      ],
      Reactions::of($outer)
    );
  }

  public function test_error_is_captured_on_the_row(): void {
    $event = new FakeDomainEvent(1);

    Reactions::open($event);
    Reactions::record('BrokenHandler', 5, new \RuntimeException('welcome mail bounced'));
    Reactions::close();

    $rows = Reactions::of($event);
    $this->assertCount(1, $rows);
    $this->assertSame('BrokenHandler', $rows[0]['handler']);
    $this->assertSame('welcome mail bounced', $rows[0]['error']);
  }

  public function test_two_instances_of_the_same_class_attribute_independently(): void {
    $first = new FakeDomainEvent(1);
    $second = new FakeDomainEvent(1);

    Reactions::open($first);
    Reactions::record('HandlerA', 1);
    Reactions::close();
    Reactions::open($second);
    Reactions::record('HandlerB', 2);
    Reactions::close();

    $this->assertSame([['handler' => 'HandlerA', 'duration_ms' => 1]], Reactions::of($first));
    $this->assertSame([['handler' => 'HandlerB', 'duration_ms' => 2]], Reactions::of($second));
  }

  public function test_of_unknown_instance_returns_empty(): void {
    $this->assertSame([], Reactions::of(new FakeDomainEvent(9)));
  }

  // ── the dispatcher bracket ──

  public function test_dispatcher_opens_a_frame_around_do_action(): void {
    $published = new FakeDomainEvent(7);
    add_action($published::action(), static function (...$args): void {
      Reactions::record('ListenerDuringDispatch', 3);
    });

    (new WordPressEventDispatcher())->dispatch($published);

    $this->assertSame(
      [['handler' => 'ListenerDuringDispatch', 'duration_ms' => 3]],
      Reactions::of($published),
      'a record() during dispatch lands on the published instance'
    );

    // Frame closed: a record() after dispatch is a no-op.
    Reactions::record('AfterDispatch', 1);
    $this->assertCount(1, Reactions::of($published));
  }

  public function test_dispatcher_pops_the_frame_even_when_a_listener_throws(): void {
    $published = new FakeDomainEvent(7);
    add_action($published::action(), static function (...$args): void {
      throw new \RuntimeException('listener blew up');
    });

    try {
      (new WordPressEventDispatcher())->dispatch($published);
      $this->fail('listener exceptions must propagate');
    } catch (\RuntimeException) {
    }

    // Frame popped by the finally: a record() now is a no-op.
    Reactions::record('AfterThrow', 1);
    $this->assertSame([], Reactions::of($published));
  }

  // ── the handler timing wrap ──

  public function test_framework_handler_records_itself_against_the_published_instance(): void {
    $published = new FakeDomainEvent(42, 'updated');
    new FakeReactingHandler();

    (new WordPressEventDispatcher())->dispatch($published);

    $rows = Reactions::of($published);
    $this->assertCount(1, $rows);
    $this->assertSame(FakeReactingHandler::class, $rows[0]['handler']);
    $this->assertIsInt($rows[0]['duration_ms']);
    $this->assertArrayNotHasKey('error', $rows[0]);

    // The reconstruction trap, made explicit: the handler saw its OWN
    // hydrated instance, not the published one — the WeakMap row still
    // landed on the published instance via the dispatch stack.
    $this->assertCount(1, FakeReactingHandler::$handled);
    $this->assertNotSame($published, FakeReactingHandler::$handled[0]);
    $this->assertSame(42, FakeReactingHandler::$handled[0]->entity_id);
  }

  public function test_throwing_handler_records_error_and_rethrows(): void {
    $published = new FakeDomainEvent(42);
    new FakeReactingHandler();
    FakeReactingHandler::$throw = new \RuntimeException('reaction failed');

    try {
      (new WordPressEventDispatcher())->dispatch($published);
      $this->fail('handler exceptions are rethrown, never swallowed');
    } catch (\RuntimeException $e) {
      $this->assertSame('reaction failed', $e->getMessage());
    }

    $rows = Reactions::of($published);
    $this->assertCount(1, $rows);
    $this->assertSame(FakeReactingHandler::class, $rows[0]['handler']);
    $this->assertSame('reaction failed', $rows[0]['error']);

    // And the dispatch frame is gone (try/finally on both sides).
    Reactions::record('AfterThrow', 1);
    $this->assertCount(1, Reactions::of($published));
  }
}
