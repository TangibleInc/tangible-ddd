<?php

namespace TangibleDDD\Tests\Unit\Outbox;

use PHPUnit\Framework\TestCase;
use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\Services\OutboxProcessor;
use TangibleDDD\Infra\IOutboxRepository;
use TangibleDDD\Tests\Fakes\FakeDDDConfig;

class OutboxProcessorTest extends TestCase {

  private FakeDDDConfig $config;

  protected function setUp(): void {
    $this->config = new FakeDDDConfig();
  }

  private function make_entry(string $event_id, int $attempts = 0, int $max_attempts = 5): OutboxEntry {
    return new OutboxEntry(
      id: 1,
      event_id: $event_id,
      event_type: 'test_event',
      integration_action: 'test_integration_test_event',
      message_kind: 'event',
      transport: 'action_scheduler',
      queue: null,
      payload_bytes: 50,
      correlation_id: 'corr-1',
      sequence: 1,
      command_id: 'cmd-1',
      payload: ['key' => 'value'],
      delay_seconds: 0,
      scheduled_at: '2025-01-01 00:00:00',
      is_unique: false,
      status: 'pending',
      attempts: $attempts,
      max_attempts: $max_attempts,
      next_attempt_at: null,
      locked_until: null,
      locked_by: null,
      last_error: null,
      error_history: null,
      created_at: '2025-01-01 00:00:00',
      processed_at: null,
      blog_id: 1,
    );
  }

  public function test_empty_batch_returns_zero_result(): void {
    $repo = $this->createMock(IOutboxRepository::class);
    $repo->expects($this->once())->method('release_stale_locks');
    $repo->method('fetch_pending')->willReturn([]);

    $publisher = $this->createMock(IOutboxPublisher::class);

    $processor = new OutboxProcessor($this->config, $repo, new OutboxConfig(), $publisher);
    $result = $processor->process_batch();

    $this->assertSame(0, $result->total);
    $this->assertSame(0, $result->completed);
    $this->assertSame(0, $result->failed);
    $this->assertSame(0, $result->dlq);
  }

  public function test_successful_entries_marked_completed(): void {
    $entry = $this->make_entry('evt-1');

    $repo = $this->createMock(IOutboxRepository::class);
    $repo->method('release_stale_locks');
    $repo->method('fetch_pending')->willReturn([$entry]);
    $repo->expects($this->once())->method('mark_completed')->with('evt-1');

    $publisher = $this->createMock(IOutboxPublisher::class);
    $publisher->expects($this->once())->method('publish');

    $processor = new OutboxProcessor($this->config, $repo, new OutboxConfig(), $publisher);
    $result = $processor->process_batch();

    $this->assertSame(1, $result->completed);
    $this->assertSame(0, $result->failed);
    $this->assertSame(0, $result->dlq);
    $this->assertSame(1, $result->total);
  }

  public function test_failed_entry_marked_failed_when_retries_remain(): void {
    $entry = $this->make_entry('evt-2', attempts: 1, max_attempts: 5);

    $repo = $this->createMock(IOutboxRepository::class);
    $repo->method('release_stale_locks');
    $repo->method('fetch_pending')->willReturn([$entry]);
    $repo->expects($this->once())->method('mark_failed')->with('evt-2', 'Publish error');
    $repo->expects($this->never())->method('move_to_dlq');

    $publisher = $this->createMock(IOutboxPublisher::class);
    $publisher->method('publish')->willThrowException(new \RuntimeException('Publish error'));

    $processor = new OutboxProcessor($this->config, $repo, new OutboxConfig(), $publisher);
    $result = $processor->process_batch();

    $this->assertSame(0, $result->completed);
    $this->assertSame(1, $result->failed);
    $this->assertSame(0, $result->dlq);
  }

  public function test_failed_entry_moved_to_dlq_when_max_attempts_reached(): void {
    // attempts=4 means next attempt (4+1=5) hits max_attempts=5
    $entry = $this->make_entry('evt-3', attempts: 4, max_attempts: 5);

    $repo = $this->createMock(IOutboxRepository::class);
    $repo->method('release_stale_locks');
    $repo->method('fetch_pending')->willReturn([$entry]);
    $repo->expects($this->once())->method('move_to_dlq')->with('evt-3');
    $repo->expects($this->never())->method('mark_failed');

    $publisher = $this->createMock(IOutboxPublisher::class);
    $publisher->method('publish')->willThrowException(new \RuntimeException('Fatal'));

    $processor = new OutboxProcessor($this->config, $repo, new OutboxConfig(), $publisher);
    $result = $processor->process_batch();

    $this->assertSame(0, $result->completed);
    $this->assertSame(0, $result->failed);
    $this->assertSame(1, $result->dlq);
  }

  public function test_mixed_batch_counts_correctly(): void {
    $good = $this->make_entry('evt-ok', attempts: 0);
    $retry = $this->make_entry('evt-retry', attempts: 0);
    $dead = $this->make_entry('evt-dead', attempts: 4, max_attempts: 5);

    $repo = $this->createMock(IOutboxRepository::class);
    $repo->method('release_stale_locks');
    $repo->method('fetch_pending')->willReturn([$good, $retry, $dead]);
    $repo->expects($this->once())->method('mark_completed')->with('evt-ok');
    $repo->expects($this->once())->method('mark_failed')->with('evt-retry', 'fail');
    $repo->expects($this->once())->method('move_to_dlq')->with('evt-dead');

    $call_count = 0;
    $publisher = $this->createMock(IOutboxPublisher::class);
    $publisher->method('publish')->willReturnCallback(function () use (&$call_count) {
      $call_count++;
      if ($call_count > 1) throw new \RuntimeException('fail');
    });

    $processor = new OutboxProcessor($this->config, $repo, new OutboxConfig(), $publisher);
    $result = $processor->process_batch();

    $this->assertSame(1, $result->completed);
    $this->assertSame(1, $result->failed);
    $this->assertSame(1, $result->dlq);
    $this->assertSame(3, $result->total);
  }

  public function test_payload_wrapped_with_correlation_context(): void {
    $entry = $this->make_entry('evt-wrap');

    $repo = $this->createMock(IOutboxRepository::class);
    $repo->method('release_stale_locks');
    $repo->method('fetch_pending')->willReturn([$entry]);
    $repo->method('mark_completed');

    $captured_payload = null;
    $publisher = $this->createMock(IOutboxPublisher::class);
    $publisher->method('publish')->willReturnCallback(function ($entry, $payload) use (&$captured_payload) {
      $captured_payload = $payload;
    });

    $processor = new OutboxProcessor($this->config, $repo, new OutboxConfig(), $publisher);
    $processor->process_batch();

    $this->assertArrayHasKey('__correlation_id', $captured_payload);
    $this->assertArrayHasKey('__sequence', $captured_payload);
    $this->assertArrayHasKey('__event_id', $captured_payload);
    $this->assertSame('corr-1', $captured_payload['__correlation_id']);
    $this->assertSame('evt-wrap', $captured_payload['__event_id']);
  }

  public function test_delivery_with_a_listener_is_not_flagged_unheard(): void {
    global $_test_actions, $_test_did_actions;
    $_test_actions = [];
    $_test_did_actions = [];
    add_action('test_integration_test_event', static function (): void {});

    $entry = $this->make_entry('evt-heard');
    $repo = new \TangibleDDD\Tests\Fakes\FakeOutboxRepository();
    $repo->entries[] = $entry; // any pending row will do — fetch returns it

    $publisher = $this->createMock(IOutboxPublisher::class);
    $publisher->expects($this->once())->method('publish');

    $processor = new OutboxProcessor($this->config, $repo, new OutboxConfig(), $publisher);
    $result = $processor->process_batch();

    $this->assertSame(1, $result->completed);
    $this->assertSame(0, did_action('tangible_ddd_fact_delivered_unheard'));
    $this->assertSame([], $repo->unheard_notes, 'a heard delivery leaves no note');
  }

  public function test_delivery_with_zero_listeners_still_delivers_but_flags_unheard(): void {
    global $_test_actions, $_test_did_actions;
    $_test_actions = [];
    $_test_did_actions = [];

    $entry = $this->make_entry('evt-unheard');
    $repo = new \TangibleDDD\Tests\Fakes\FakeOutboxRepository();
    $repo->entries[] = $entry;

    $seen = null;
    add_action('tangible_ddd_fact_delivered_unheard', static function ($event) use (&$seen): void {
      $seen = $event;
    });

    $publisher = $this->createMock(IOutboxPublisher::class);
    $publisher->expects($this->once())->method('publish'); // contract unchanged: deliver anyway

    $processor = new OutboxProcessor($this->config, $repo, new OutboxConfig(), $publisher);
    $result = $processor->process_batch();

    $this->assertSame(1, $result->completed, 'status stays completed');
    $this->assertSame(1, did_action('tangible_ddd_fact_delivered_unheard'));
    $this->assertInstanceOf(\TangibleDDD\Application\Infrastructure\FactDeliveredUnheard::class, $seen);
    $this->assertSame('evt-unheard', $seen->entry()->event_id);

    $this->assertArrayHasKey('evt-unheard', $repo->unheard_notes);
    $this->assertStringContainsString('zero listeners', $repo->unheard_notes['evt-unheard']);
  }

  public function test_unheard_note_is_skipped_on_repositories_without_the_method(): void {
    // A consumer-authored IOutboxRepository predating this release must not
    // fatal — the note is best-effort, guarded by method_exists.
    global $_test_actions, $_test_did_actions;
    $_test_actions = [];
    $_test_did_actions = [];

    $entry = $this->make_entry('evt-bare');
    $repo = $this->createMock(IOutboxRepository::class);
    $repo->method('release_stale_locks');
    $repo->method('fetch_pending')->willReturn([$entry]);
    $repo->expects($this->once())->method('mark_completed')->with('evt-bare');

    $publisher = $this->createMock(IOutboxPublisher::class);

    $processor = new OutboxProcessor($this->config, $repo, new OutboxConfig(), $publisher);
    $result = $processor->process_batch();

    $this->assertSame(1, $result->completed);
    $this->assertSame(1, did_action('tangible_ddd_fact_delivered_unheard'), 'the observability action still fires');
  }

  public function test_stale_locks_released_before_fetching(): void {
    $repo = $this->createMock(IOutboxRepository::class);
    // release_stale_locks should be called with the config value
    $repo->expects($this->once())->method('release_stale_locks')->with(600);
    $repo->method('fetch_pending')->willReturn([]);

    $publisher = $this->createMock(IOutboxPublisher::class);
    $outbox_config = new OutboxConfig(lock_timeout_seconds: 600);

    $processor = new OutboxProcessor($this->config, $repo, $outbox_config, $publisher);
    $processor->process_batch();
  }
}
