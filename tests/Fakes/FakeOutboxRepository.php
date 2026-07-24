<?php

namespace TangibleDDD\Tests\Fakes;

use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Infra\IOutboxRepository;

final class FakeOutboxRepository implements IOutboxRepository {

  /** @var OutboxEntry[] */
  public array $entries = [];

  /** @var string[] */
  public array $completed = [];

  /** @var array<string, string> event_id => error */
  public array $failed = [];

  /** @var string[] */
  public array $dlq = [];

  /** @var array<string, string> event_id => unheard note */
  public array $unheard_notes = [];

  /** @var int */
  public int $stale_locks_released = 0;

  /** @var int */
  public int $duplicates_cancelled = 0;

  /** @var array<string, array{selector: string, until: int}> */
  public array $pauses = [];

  private int $next_id = 1;

  public function write(IIntegrationEvent $event, string $correlation_id, ?string $command_id = null): string {
    $event_id = 'evt_' . $this->next_id++;

    $this->entries[] = new OutboxEntry(
      id: count($this->entries) + 1,
      event_id: $event_id,
      event_type: $event::name(),
      integration_action: $event::integration_action(),
      message_kind: 'event',
      transport: 'action_scheduler',
      queue: null,
      payload_bytes: 0,
      correlation_id: $correlation_id,
      sequence: 0,
      command_id: $command_id,
      payload: $event->integration_payload(),
      delay_seconds: $event->delay(),
      scheduled_at: gmdate('Y-m-d H:i:s'),
      is_unique: $event->is_unique(),
      status: 'pending',
      attempts: 0,
      max_attempts: 5,
      next_attempt_at: null,
      locked_until: null,
      locked_by: null,
      last_error: null,
      error_history: null,
      created_at: gmdate('Y-m-d H:i:s'),
      processed_at: null,
      blog_id: 1,
    );

    return $event_id;
  }

  public function fetch_pending(int $limit = 50, ?string $worker_id = null): array {
    $paused = $this->active_pause_selectors();
    if (in_array('*', $paused, true)) {
      return [];
    }
    return array_values(array_filter(
      $this->entries,
      fn(OutboxEntry $e) => $e->status === 'pending' && !in_array($e->event_type, $paused, true)
    ));
  }

  public function find_by_event_id(string $event_id): ?OutboxEntry {
    foreach ($this->entries as $entry) {
      if ($entry->event_id === $event_id) return $entry;
    }
    return null;
  }

  public function mark_completed(string $event_id): void {
    $this->completed[] = $event_id;
  }

  public function mark_failed(string $event_id, string $error): void {
    $this->failed[$event_id] = $error;
  }

  public function note_delivered_unheard(string $event_id, string $note): void {
    $this->unheard_notes[$event_id] = $note;
  }

  public function move_to_dlq(string $event_id, string $final_error = ''): void {
    $this->dlq[] = $event_id;
  }

  public function release_stale_locks(int $timeout_seconds = 300): int {
    $this->stale_locks_released++;
    return 0;
  }

  public function cancel_duplicates(string $event_type, array $payload_signature): int {
    $this->duplicates_cancelled++;
    return 0;
  }

  public function get_stats(): array {
    return ['pending' => count($this->entries)];
  }

  public function purge_completed(int $older_than_days = 30): int {
    return 0;
  }

  public function set_pause(string $holder, string $selector, int $until = -1): void {
    $this->pauses[$holder] = ['selector' => $selector, 'until' => $until];
  }

  public function clear_pause(string $holder): void {
    unset($this->pauses[$holder]);
  }

  public function is_paused(string $event_type): bool {
    $active = $this->active_pause_selectors();
    return in_array('*', $active, true) || in_array($event_type, $active, true);
  }

  /** @return string[] */
  private function active_pause_selectors(): array {
    $now = time();
    $selectors = [];
    foreach ($this->pauses as $hold) {
      $until = (int) ($hold['until'] ?? -1);
      if ($until !== -1 && $until <= $now) {
        continue;
      }
      $selector = (string) ($hold['selector'] ?? '');
      if ($selector !== '') {
        $selectors[] = $selector;
      }
    }
    return array_values(array_unique($selectors));
  }
}
