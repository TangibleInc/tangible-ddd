<?php

namespace TangibleDDD\Infra\Persistence;

use DateTimeImmutable;
use TangibleDDD\Application\Correlation\CorrelationContext;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IOutboxRepository;

/**
 * MySQL/WordPress implementation of the outbox repository.
 */
class OutboxRepository implements IOutboxRepository {

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly OutboxConfig $outbox_config
  ) {}

  public function write(
    IIntegrationEvent $event,
    string $correlation_id,
    ?string $command_id = null
  ): string {
    global $wpdb;

    $event_id = $this->generate_uuid();
    $now = gmdate('Y-m-d H:i:s');
    $delay_seconds = max(0, $event->delay());
    $scheduled_at = gmdate('Y-m-d H:i:s', time() + $delay_seconds);
    $payload_json = wp_json_encode($event->integration_payload(), JSON_UNESCAPED_SLASHES);
    $payload_bytes = strlen($payload_json);

    $row = [
      'event_id' => $event_id,
      'event_type' => $event::name(),
      'integration_action' => $event::integration_action(),
      'message_kind' => 'integration_event',
      'transport' => 'action_scheduler',
      'queue' => $this->config->prefix() . '-outbox',
      'payload_bytes' => $payload_bytes,
      'correlation_id' => $correlation_id,
      'sequence' => CorrelationContext::next_sequence(),
      'command_id' => $command_id,
      'payload' => $payload_json,
      'delay_seconds' => $delay_seconds,
      'scheduled_at' => $scheduled_at,
      'is_unique' => $event->is_unique() ? 1 : 0,
      'status' => 'pending',
      'attempts' => 0,
      'max_attempts' => $this->outbox_config->max_attempts,
      'created_at' => $now,
      'blog_id' => is_multisite() ? get_current_blog_id() : 1,
    ];

    $wpdb->insert($this->table_name(), $row);

    return $event_id;
  }

  public function fetch_pending(int $limit = 50, ?string $worker_id = null): array {
    global $wpdb;

    $table = $this->table_name();
    $now = gmdate('Y-m-d H:i:s');
    $lock_until = gmdate('Y-m-d H:i:s', time() + 300); // 5 minute lock
    $worker_id = $worker_id ?? $this->get_worker_id();

    // Fetch and lock pending entries in one query
    // Only fetch entries that are:
    // - pending status
    // - scheduled_at has passed
    // - either no next_attempt_at or it has passed
    // - not currently locked (or lock expired)
    $sql = $wpdb->prepare(
      "SELECT * FROM `$table`
       WHERE status = 'pending'
         AND scheduled_at <= %s
         AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
         AND (locked_until IS NULL OR locked_until <= %s)
       ORDER BY scheduled_at ASC
       LIMIT %d
       FOR UPDATE SKIP LOCKED",
      $now,
      $now,
      $now,
      $limit
    );

    // Start transaction for fetch + lock
    $wpdb->query('START TRANSACTION');

    $rows = $wpdb->get_results($sql);

    if (empty($rows)) {
      $wpdb->query('COMMIT');
      return [];
    }

    // Lock the fetched entries
    $ids = array_map(fn($row) => (int) $row->id, $rows);
    $ids_placeholder = implode(',', array_fill(0, count($ids), '%d'));

    $wpdb->query($wpdb->prepare(
      "UPDATE `$table`
       SET locked_until = %s, locked_by = %s
       WHERE id IN ($ids_placeholder)",
      array_merge([$lock_until, $worker_id], $ids)
    ));

    $wpdb->query('COMMIT');

    return array_map(fn($row) => $this->entry_from_row($row), $rows);
  }

  public function find_by_event_id(string $event_id): ?OutboxEntry {
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM `{$this->table_name()}` WHERE event_id = %s",
      $event_id
    ));

    return $row ? $this->entry_from_row($row) : null;
  }

  public function mark_completed(string $event_id): void {
    global $wpdb;

    $wpdb->update(
      $this->table_name(),
      [
        'status' => 'completed',
        'processed_at' => gmdate('Y-m-d H:i:s'),
        'locked_until' => null,
        'locked_by' => null,
      ],
      ['event_id' => $event_id]
    );
  }

  public function mark_failed(string $event_id, string $error): void {
    global $wpdb;

    $entry = $this->find_by_event_id($event_id);
    if (!$entry) return;

    $new_attempts = $entry->attempts + 1;

    // Exponential backoff: base * multiplier^(attempts-1), capped at max
    $delay = min(
      $this->outbox_config->base_retry_delay_seconds * pow($this->outbox_config->retry_multiplier, $new_attempts - 1),
      $this->outbox_config->max_retry_delay_seconds
    );

    $next_attempt_at = gmdate('Y-m-d H:i:s', time() + (int) $delay);

    // Append to error history
    $error_history = $entry->error_history ?? [];
    $error_history[] = [
      'attempt' => $new_attempts,
      'error' => $error,
      'timestamp' => gmdate('Y-m-d H:i:s'),
    ];

    $wpdb->update(
      $this->table_name(),
      [
        'status' => 'pending', // Back to pending for retry
        'attempts' => $new_attempts,
        'last_error' => $error,
        'error_history' => wp_json_encode($error_history),
        'next_attempt_at' => $next_attempt_at,
        'locked_until' => null,
        'locked_by' => null,
      ],
      ['event_id' => $event_id]
    );
  }

  public function move_to_dlq(string $event_id): void {
    global $wpdb;

    $entry = $this->find_by_event_id($event_id);
    if (!$entry) return;

    // Insert into DLQ
    $wpdb->insert(
      $this->dlq_table_name(),
      [
        'outbox_id' => $entry->id,
        'event_id' => $entry->event_id,
        'event_type' => $entry->event_type,
        'integration_action' => $entry->integration_action,
        'correlation_id' => $entry->correlation_id,
        'command_id' => $entry->command_id,
        'payload' => wp_json_encode($entry->payload, JSON_UNESCAPED_SLASHES),
        'attempts' => $entry->attempts,
        'error_history' => wp_json_encode($entry->error_history),
        'final_error' => $entry->last_error,
        'moved_at' => gmdate('Y-m-d H:i:s'),
        'blog_id' => $entry->blog_id,
      ]
    );

    // Update outbox status
    $wpdb->update(
      $this->table_name(),
      [
        'status' => 'dlq',
        'locked_until' => null,
        'locked_by' => null,
      ],
      ['event_id' => $event_id]
    );
  }

  public function release_stale_locks(int $timeout_seconds = 300): int {
    global $wpdb;

    $cutoff = gmdate('Y-m-d H:i:s', time() - $timeout_seconds);

    return (int) $wpdb->query($wpdb->prepare(
      "UPDATE `{$this->table_name()}`
       SET locked_until = NULL, locked_by = NULL
       WHERE locked_until IS NOT NULL AND locked_until < %s",
      $cutoff
    ));
  }

  public function cancel_duplicates(string $event_type, array $payload_signature): int {
    global $wpdb;

    // For is_unique events, cancel older pending events of the same type
    // We compare by event_type only for simplicity; more sophisticated
    // implementations could hash the payload for exact matching
    return (int) $wpdb->query($wpdb->prepare(
      "UPDATE `{$this->table_name()}`
       SET status = 'cancelled'
       WHERE event_type = %s
         AND status = 'pending'
         AND is_unique = 1",
      $event_type
    ));
  }

  public function get_stats(): array {
    global $wpdb;

    $table = $this->table_name();
    $rows = $wpdb->get_results(
      "SELECT status, COUNT(*) as count FROM `$table` GROUP BY status"
    );

    $stats = [];
    foreach ($rows as $row) {
      $stats[$row->status] = (int) $row->count;
    }

    // Also get DLQ count
    $dlq_count = (int) $wpdb->get_var(
      "SELECT COUNT(*) FROM `{$this->dlq_table_name()}` WHERE resolved_at IS NULL"
    );
    $stats['dlq_unresolved'] = $dlq_count;

    return $stats;
  }

  public function purge_completed(int $older_than_days = 30): int {
    global $wpdb;

    $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$older_than_days} days"));

    return (int) $wpdb->query($wpdb->prepare(
      "DELETE FROM `{$this->table_name()}`
       WHERE status = 'completed' AND processed_at < %s",
      $cutoff
    ));
  }

  private function table_name(): string {
    return $this->config->table('integration_outbox');
  }

  private function dlq_table_name(): string {
    return $this->config->table('integration_dlq');
  }

  private function entry_from_row(object $row): OutboxEntry {
    return new OutboxEntry(
      id: (int) $row->id,
      event_id: $row->event_id,
      event_type: $row->event_type,
      integration_action: $row->integration_action,
      message_kind: $row->message_kind ?? 'integration_event',
      transport: $row->transport ?? 'action_scheduler',
      queue: $row->queue ?? null,
      payload_bytes: (int) ($row->payload_bytes ?? 0),
      correlation_id: $row->correlation_id,
      sequence: (int) ($row->sequence ?? 1),
      command_id: $row->command_id,
      payload: json_decode($row->payload, true) ?? [],
      delay_seconds: (int) $row->delay_seconds,
      scheduled_at: new DateTimeImmutable($row->scheduled_at),
      is_unique: (bool) $row->is_unique,
      status: $row->status,
      attempts: (int) $row->attempts,
      max_attempts: (int) $row->max_attempts,
      next_attempt_at: $row->next_attempt_at ? new DateTimeImmutable($row->next_attempt_at) : null,
      last_error: $row->last_error,
      error_history: $row->error_history ? json_decode($row->error_history, true) : null,
      created_at: new DateTimeImmutable($row->created_at),
      processed_at: $row->processed_at ? new DateTimeImmutable($row->processed_at) : null,
      blog_id: (int) $row->blog_id,
    );
  }

  private function generate_uuid(): string {
    try {
      return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
      );
    } catch (\Exception $e) {
      return wp_generate_uuid4();
    }
  }

  private function get_worker_id(): string {
    return gethostname() . '-' . getmypid();
  }
}
