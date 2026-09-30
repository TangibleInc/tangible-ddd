<?php

namespace TangibleDDD\Infra\Services;

use TangibleDDD\Application\Infrastructure\FactDeliveredUnheard;
use TangibleDDD\Application\Infrastructure\OutboxAttemptFailed;
use TangibleDDD\Application\Infrastructure\OutboxDeadLettered;
use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Infra\IOutboxRepository;
use Throwable;

/**
 * Processes the transactional outbox, publishing events to ActionScheduler.
 *
 * This is a transactional-outbox RELAY — transport/persistence mechanics
 * (fetch → publish → mark, locking, retry, DLQ), no domain content — so it lives
 * in Infra\Services alongside the publishers and bus it drives (moved here from
 * Application\Outbox, where it was mislabelled). Run periodically via
 * cron/ActionScheduler; handles:
 * - Fetching pending events (which honour relay pauses — see IOutboxRepository)
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

    // Fetch pending entries (acquires lock). Paused event types are excluded by
    // the repository itself, so this returns nothing (or fewer rows) while paused.
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
        // Delivered-to-nobody check happens BEFORE firing: has_action reads
        // the listener table as it stands at drain time. The contract is
        // unchanged either way — an unheard fact is still delivered.
        $unheard = function_exists('has_action') && !has_action($entry->integration_action);

        $wrapped = $this->wrap_payload_for_transport($entry);
        $this->publisher->publish($entry, $wrapped);
        $this->outbox->mark_completed($entry->event_id);
        $completed++;

        $this->log_event('completed', $entry);

        if ($unheard) {
          // Observability, not failure: status stays completed. The action
          // is for monitors; the row note survives for the dashboard.
          // Fires {prefix}_fact_delivered_unheard + the global
          // tangible_ddd_fact_delivered_unheard.
          (new FactDeliveredUnheard($entry))->dispatch($this->config);

          // Best-effort, additive: the note method lives on the concrete
          // repository, NOT on IOutboxRepository — a consumer-authored
          // implementation predating this release must not fatal here.
          if (method_exists($this->outbox, 'note_delivered_unheard')) {
            $this->outbox->note_delivered_unheard(
              $entry->event_id,
              sprintf('delivered with zero listeners on "%s"', $entry->integration_action)
            );
          }
        }

      } catch (Throwable $e) {
        $new_attempts = $entry->attempts + 1;

        if ($new_attempts >= $entry->max_attempts) {
          // Pass the TRUE final error: the final attempt skips mark_failed, so
          // the row's last_error is the prior attempt's. This is the exception
          // that actually caused the dead-letter.
          $this->outbox->move_to_dlq($entry->event_id, $e->getMessage());
          $dlq++;
          $this->log_event('dlq', $entry, $e->getMessage());

          // Infrastructure event — terminal failure, out-of-band. Carries the
          // dead event's correlation + event_id so a listener (e.g. escalation)
          // rejoins the original trace. Fires {prefix}_outbox_dlq + the global
          // tangible_ddd_outbox_dlq (see InfrastructureEvent::dispatch).
          (new OutboxDeadLettered($entry, $e->getMessage()))->dispatch($this->config);
        } else {
          $this->outbox->mark_failed($entry->event_id, $e->getMessage());
          $failed++;
          $this->log_event('failed', $entry, $e->getMessage());

          // Infrastructure event — transient retry-pressure signal (metrics),
          // not an alert. Event stays queued for the next attempt.
          (new OutboxAttemptFailed($entry, $new_attempts, $entry->max_attempts, $e->getMessage()))->dispatch($this->config);
        }
      }
    }

    return new ProcessingResult($completed, $failed, $dlq, count($entries));
  }

  /**
   * Wrap payload with correlation context for downstream tracing.
   *
   * Delegates to the envelope — wrap() and unwrap() are one codec in one
   * home (0.2.5); this processor no longer knows the __-key wire format.
   */
  private function wrap_payload_for_transport(OutboxEntry $entry): array {
    return \TangibleDDD\Application\Events\IntegrationEnvelope::wrap(
      $entry->payload,
      $entry->correlation_id,
      $entry->sequence,
      $entry->event_id,
    );
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
