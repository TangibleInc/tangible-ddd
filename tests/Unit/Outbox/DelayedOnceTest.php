<?php

namespace TangibleDDD\Tests\Unit\Outbox;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\Services\ActionSchedulerOutboxPublisher;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;
use TangibleDDD\Tests\Fakes\FakeDelayedIntegrationEvent;
use TangibleDDD\Tests\Fakes\FakeOutboxRepository;

/**
 * Bug 3 (contract register section 6): a delayed fact was delayed twice.
 * The outbox row carries scheduled_at = written + delay and the relay only
 * fetches it once that has passed; the Action Scheduler publisher then added
 * delay_seconds AGAIN (and again on every retry). The due time is absolute
 * UTC on the row and honoured once: the publisher schedules at
 * max(now, scheduled_at), enqueueing async when that is not in the future.
 *
 * Scenario: delivery.delayed-once.
 */
class DelayedOnceTest extends TestCase {

  protected function setUp(): void {
    global $_test_scheduled_actions;
    $_test_scheduled_actions = [];
  }

  private function entry(int $delay_seconds, int $scheduled_at, int $attempts = 0): OutboxEntry {
    return new OutboxEntry(
      id: 1,
      event_id: 'evt-delayed',
      event_type: 'test_event',
      integration_action: 'test_integration_test_event',
      message_kind: 'event',
      transport: 'action_scheduler',
      queue: null,
      payload_bytes: 2,
      correlation_id: 'corr-1',
      sequence: 1,
      command_id: null,
      payload: [],
      delay_seconds: $delay_seconds,
      scheduled_at: gmdate('Y-m-d H:i:s', $scheduled_at),
      is_unique: false,
      status: 'pending',
      attempts: $attempts,
      max_attempts: 5,
      next_attempt_at: null,
      locked_until: null,
      locked_by: null,
      last_error: null,
      error_history: null,
      created_at: gmdate('Y-m-d H:i:s', $scheduled_at - $delay_seconds),
      processed_at: null,
      blog_id: 1,
    );
  }

  private function publisher(): ActionSchedulerOutboxPublisher {
    return new ActionSchedulerOutboxPublisher(new OutboxConfig());
  }

  public function test_a_due_delayed_row_is_enqueued_now_not_delayed_again(): void {
    global $_test_scheduled_actions;

    // Written 60s ago with delay 60: the relay fetched it because it is due.
    $this->publisher()->publish($this->entry(60, time()), ['wrapped' => true]);

    $this->assertCount(1, $_test_scheduled_actions);
    $this->assertArrayNotHasKey('timestamp', $_test_scheduled_actions[0], 'due now: async, no second delay');
    $this->assertSame([['wrapped' => true]], $_test_scheduled_actions[0]['args']);
  }

  public function test_a_retry_of_a_delayed_row_is_not_delayed_again(): void {
    global $_test_scheduled_actions;

    // Third attempt, long after the original due time; retries are gated by
    // next_attempt_at in the relay, never by delay_seconds in the publisher.
    $this->publisher()->publish($this->entry(60, time() - 3600, attempts: 2), []);

    $this->assertArrayNotHasKey('timestamp', $_test_scheduled_actions[0]);
  }

  public function test_a_row_published_before_its_due_time_is_scheduled_at_it(): void {
    global $_test_scheduled_actions;

    $due = time() + 45;
    $this->publisher()->publish($this->entry(60, $due), []);

    $this->assertSame($due, $_test_scheduled_actions[0]['timestamp'] ?? null, 'absolute due time, not now + delay');
  }

  public function test_relay_delivers_a_delayed_fact_exactly_one_delay_after_it_was_written(): void {
    global $_test_scheduled_actions;

    $repo = new FakeOutboxRepository();
    $processor = new OutboxProcessor(new FakeDDDConfig(), $repo, new OutboxConfig(), $this->publisher());

    $written_at = time() - 60;   // delay() is 60: the fact is due right now
    $repo->now = $written_at;
    $repo->write(new FakeDelayedIntegrationEvent(), 'corr-1');

    $this->assertSame(0, $processor->process_batch()->total, 'not relayed before its due time');

    $repo->now = null;   // real clock: written + 60
    $this->assertSame(1, $processor->process_batch()->total);

    $this->assertCount(1, $_test_scheduled_actions);
    $due = $_test_scheduled_actions[0]['timestamp'] ?? time();
    $this->assertLessThanOrEqual($written_at + 60 + 1, $due, 'delivered one delay after writing, not two');
  }
}
