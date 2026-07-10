<?php

namespace TangibleDDD\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Infrastructure\OutboxAttemptFailed;
use TangibleDDD\Application\Infrastructure\OutboxDeadLettered;
use TangibleDDD\Application\Infrastructure\ProcessFailed;
use TangibleDDD\Application\Infrastructure\WorkflowFailed;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

/**
 * Infrastructure events carry provenance so a listener rejoins the trace:
 * outbox events point causation at the dead event (integration_event); the
 * action() names are the stable hook names dispatch() fires on.
 */
class InfrastructureEventTest extends TestCase {

  private function entry(): OutboxEntry {
    return new OutboxEntry(
      id: 1,
      event_id: 'evt-1',
      event_type: 'test_event',
      integration_action: 'test_action',
      message_kind: 'event',
      transport: 'action_scheduler',
      queue: null,
      payload_bytes: 10,
      correlation_id: 'corr-1',
      sequence: 1,
      command_id: 'cmd-1',
      payload: ['destination_id' => 4],
      delay_seconds: 0,
      scheduled_at: '2025-01-01 00:00:00',
      is_unique: false,
      status: 'dlq',
      attempts: 5,
      max_attempts: 5,
      next_attempt_at: null,
      locked_until: null,
      locked_by: null,
      last_error: 'prior attempt error',
      error_history: null,
      created_at: '2025-01-01 00:00:00',
      processed_at: null,
      blog_id: 1,
    );
  }

  public function test_dead_lettered_provenance(): void {
    $ev = new OutboxDeadLettered($this->entry(), 'the TRUE final error');

    $this->assertSame('outbox_dlq', OutboxDeadLettered::action());
    $this->assertSame('corr-1', $ev->correlation_id(), 'rejoins the dead event\'s trace');
    $this->assertSame('evt-1', $ev->causation_id(), 'caused by the dead event');
    $this->assertSame('integration_event', $ev->causation_type());
    $this->assertSame('the TRUE final error', $ev->final_error, 'carries the real final error, not the stale row value');
    $this->assertSame(4, $ev->entry()->payload['destination_id']);
  }

  public function test_attempt_failed_fields(): void {
    $ev = new OutboxAttemptFailed($this->entry(), attempt: 3, max_attempts: 5, error: 'boom');

    $this->assertSame('outbox_attempt_failed', OutboxAttemptFailed::action());
    $this->assertSame('corr-1', $ev->correlation_id());
    $this->assertSame('evt-1', $ev->causation_id());
    $this->assertSame('integration_event', $ev->causation_type());
    $this->assertSame(3, $ev->attempt);
    $this->assertSame(5, $ev->max_attempts);
    $this->assertSame('boom', $ev->error);
  }

  public function test_failure_action_names_are_stable(): void {
    // These are the hook names dispatch() fires ({prefix}_X + tangible_ddd_X).
    $this->assertSame('process_failed', ProcessFailed::action());
    $this->assertSame('workflow_failed', WorkflowFailed::action());
  }

  public function test_dispatch_is_a_safe_noop_outside_wordpress(): void {
    // No hook system in unit context → dispatch() must not fatal.
    (new OutboxDeadLettered($this->entry(), 'err'))->dispatch(new FakeDDDConfig());
    $this->assertTrue(true);
  }
}
