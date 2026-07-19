<?php

namespace TangibleDDD\Tests\Unit\EventHandlers;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Tests\Fakes\FakeCapturingCommand;
use TangibleDDD\Tests\Fakes\FakeRecordingListener;
use TangibleDDD\Tests\Fakes\FakeResolvedEvent;

class IntegrationListenerTest extends TestCase {

  protected function setUp(): void {
    global $_test_actions;
    $_test_actions = [];
    FakeCapturingCommand::$sent = [];
    FakeRecordingListener::$received = null;
  }

  public function test_ceremony_delivers_typed_stamped_event_and_sends_command(): void {
    new FakeRecordingListener();   // ctor self-wires

    do_action(FakeResolvedEvent::integration_action(), [
      'request_id' => 312, 'outcome' => 'accepted', 'resolved_at' => '2026-07-06T10:00:00+00:00',
      '__correlation_id' => 'corr-1', '__sequence' => 2, '__event_id' => 'ev-9',
    ]);

    $received = FakeRecordingListener::$received;
    $this->assertInstanceOf(FakeResolvedEvent::class, $received);
    $this->assertSame(312, $received->request_id);
    // 0.3: hydrated twins carry no identity — the drain SCOPE carries it.
    // The ceremony test asserts the typed event arrived; identity lives on
    // the envelope (see DrainBracketTest for the scope assertions).
    $this->assertCount(1, FakeCapturingCommand::$sent);
    $this->assertSame(312, FakeCapturingCommand::$sent[0]->request_id);
    $this->assertNull(\TangibleDDD\Application\Correlation\Correlation::peek(), 'drain scope closed — nothing bleeds into the worker');
  }

  public function test_null_command_is_a_no_op(): void {
    \TangibleDDD\WordPress\integration_listener(FakeResolvedEvent::class, fn($e) => null);
    do_action(FakeResolvedEvent::integration_action(), ['request_id' => 1, 'outcome' => 'accepted', 'resolved_at' => '2026-07-06T10:00:00+00:00']);
    $this->assertCount(0, FakeCapturingCommand::$sent);
  }
}
