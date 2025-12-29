<?php

namespace TangibleDDD\Application\Outbox;

use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IOutboxRepository;
use Throwable;

/**
 * Processes the transactional outbox, publishing events to ActionScheduler.
 *
 * This processor is designed to be run periodically (via cron/ActionScheduler)
 * and handles:
 * - Fetching pending events
 * - Publishing to ActionScheduler
 * - Marking events as completed/failed
 * - Moving failed events to DLQ after max attempts
 * - Releasing stale locks from crashed workers
 */
final class OutboxProcessor {

  private string $worker_id;

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly IOutboxRepository $outbox,
    private readonly OutboxConfig $outbox_config,
    private readonly IOutboxPublisher $publisher,
  ) {
    $this->worker_id = gethostname() . '-' . getmypid();
  }

  /**
   * Process a batch of pending outbox entries.
   */
  public function process_batch(): ProcessingResult {
    // First, release any stale locks from crashed workers
    $this->outbox->release_stale_locks($this->outbox_config->lock_timeout_seconds);

    // Fetch pending entries (acquires lock)
    $entries = $this->outbox->fetch_pending(
      $this->outbox_config->batch_size,
      $this->worker_id
    );

    if (empty($entries)) {
      return new ProcessingResult(0, 0, 0, 0);
    }

    $completed = 0;
    $failed = 0;
    $dlq = 0;

    foreach ($entries as $entry) {
      try {
        $wrapped = $this->wrap_payload_for_transport($entry);
        $this->publisher->publish($entry, $wrapped);
        $this->outbox->mark_completed($entry->event_id);
        $completed++;

        $this->log_event('completed', $entry);

      } catch (Throwable $e) {
        $new_attempts = $entry->attempts + 1;

        if ($new_attempts >= $entry->max_attempts) {
          $this->outbox->move_to_dlq($entry->event_id);
          $dlq++;
          $this->log_event('dlq', $entry, $e->getMessage());
        } else {
          $this->outbox->mark_failed($entry->event_id, $e->getMessage());
          $failed++;
          $this->log_event('failed', $entry, $e->getMessage());
        }
      }
    }

    return new ProcessingResult($completed, $failed, $dlq, count($entries));
  }

  /**
   * Wrap payload with correlation context for downstream tracing.
   */
  private function wrap_payload_for_transport(OutboxEntry $entry): array {
    $payload = $entry->payload;
    $payload['__correlation_id'] = $entry->correlation_id;
    $payload['__sequence'] = $entry->sequence;
    $payload['__event_id'] = $entry->event_id;
    return $payload;
  }

  /**
   * Log event processing status.
   */
  private function log_event(string $status, OutboxEntry $entry, ?string $error = null): void {
    $context = [
      'event_id' => $entry->event_id,
      'event_type' => $entry->event_type,
      'correlation_id' => $entry->correlation_id,
      'attempts' => $entry->attempts,
      'worker_id' => $this->worker_id,
    ];

    if ($error) {
      $context['error'] = $error;
    }

    // Only log non-success or errors for cleaner logs
    if ($status !== 'completed' || defined('WP_DEBUG') && WP_DEBUG) {
      error_log(sprintf(
        '[%s-outbox] %s: %s',
        $this->config->prefix(),
        strtoupper($status),
        wp_json_encode($context)
      ));
    }
  }
}
