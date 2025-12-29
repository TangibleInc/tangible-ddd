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

  public function publish(OutboxEntry $entry, array $wrapped_payload): void {
    $group = $entry->queue ?: $this->config->action_scheduler_group;

    if ($entry->delay_seconds > 0) {
      as_schedule_single_action(
        time() + $entry->delay_seconds,
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
}
