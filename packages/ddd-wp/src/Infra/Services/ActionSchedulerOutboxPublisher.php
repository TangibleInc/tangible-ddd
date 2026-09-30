<?php

namespace TangibleDDD\Infra\Services;

use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;

/**
 * Publishes outbox entries to WordPress ActionScheduler.
 */
final class ActionSchedulerOutboxPublisher implements IOutboxPublisher {

  public function __construct(
    private readonly OutboxConfig $config
  ) {}

  /**
   * The row's scheduled_at (absolute UTC, written as created + delay) is the
   * one and only due time. The relay already withholds the row until then,
   * so delay_seconds is never added again here, neither on the first
   * attempt nor on retries (those are gated by next_attempt_at). Schedule
   * at max(now, scheduled_at); when that is not in the future, enqueue
   * async. Rows written by older code carry the same scheduled_at and are
   * therefore not delayed again either.
   */
  public function publish(OutboxEntry $entry, array $wrapped_payload): void {
    $group = $entry->queue ?: $this->config->action_scheduler_group;

    $due = self::utc_timestamp($entry->scheduled_at);

    if ($due !== null && $due > time()) {
      as_schedule_single_action(
        $due,
        $entry->integration_action,
        [$wrapped_payload],
        $group
      );
    } else {
      as_enqueue_async_action(
        $entry->integration_action,
        [$wrapped_payload],
        $group
      );
    }
  }

  /** 'Y-m-d H:i:s' in UTC (the outbox column format) → unix seconds. */
  private static function utc_timestamp(string $datetime): ?int {
    if ($datetime === '') {
      return null;
    }
    try {
      return (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))->getTimestamp();
    } catch (\Exception) {
      return null;
    }
  }
}
