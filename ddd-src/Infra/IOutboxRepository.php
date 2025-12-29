<?php

namespace TangibleDDD\Infra;

use TangibleDDD\Domain\Events\IIntegrationEvent;
use TangibleDDD\Application\Outbox\OutboxEntry;

/**
 * Repository for the transactional outbox.
 *
 * The outbox stores integration events within the same database transaction
 * as domain work, ensuring events are never lost due to crashes.
 */
interface IOutboxRepository {

  /**
   * Write an event to the outbox (within current transaction).
   *
   * @param IIntegrationEvent $event The event to store
   * @param string $correlation_id Links event to root user action
   * @param string|null $command_id Links event to command audit log
   * @return string The generated event_id
   */
  public function write(
    IIntegrationEvent $event,
    string $correlation_id,
    ?string $command_id = null
  ): string;

  /**
   * Fetch pending events ready to process.
   *
   * Acquires a lock on returned entries to prevent concurrent processing.
   *
   * @param int $limit Maximum entries to fetch
   * @param string|null $worker_id Identifier for the worker acquiring lock
   * @return OutboxEntry[]
   */
  public function fetch_pending(int $limit = 50, ?string $worker_id = null): array;

  /**
   * Find an entry by event_id.
   */
  public function find_by_event_id(string $event_id): ?OutboxEntry;

  /**
   * Mark event as successfully processed.
   */
  public function mark_completed(string $event_id): void;

  /**
   * Mark event as failed, increment attempts, schedule retry.
   *
   * @param string $event_id
   * @param string $error Error message
   */
  public function mark_failed(string $event_id, string $error): void;

  /**
   * Move event to Dead Letter Queue after max attempts exceeded.
   */
  public function move_to_dlq(string $event_id): void;

  /**
   * Release locks held by crashed/stuck workers.
   *
   * @param int $timeout_seconds Locks older than this are released
   * @return int Number of locks released
   */
  public function release_stale_locks(int $timeout_seconds = 300): int;

  /**
   * Cancel pending duplicate events for is_unique events.
   *
   * When an is_unique event is written, older pending events of the
   * same type with matching payload signature should be cancelled.
   *
   * @param string $event_type
   * @param array $payload_signature
   * @return int Number of events cancelled
   */
  public function cancel_duplicates(string $event_type, array $payload_signature): int;

  /**
   * Get count of entries by status.
   *
   * @return array<string, int> Map of status => count
   */
  public function get_stats(): array;

  /**
   * Purge completed entries older than given age.
   *
   * @param int $older_than_days
   * @return int Number of entries purged
   */
  public function purge_completed(int $older_than_days = 30): int;
}
