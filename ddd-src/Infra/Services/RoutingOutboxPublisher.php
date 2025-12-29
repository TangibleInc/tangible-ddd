<?php

namespace TangibleDDD\Infra\Services;

use RuntimeException;
use TangibleDDD\Application\Outbox\IOutboxPublisher;
use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Application\Outbox\OutboxEntry;
use TangibleDDD\Infra\IDDDConfig;

/**
 * Publisher that routes outbox entries by transport/payload size.
 *
 * - action_scheduler: publish into Action Scheduler (default)
 * - external: delegate to a filter/hook (e.g. Kafka/RabbitMQ/SQS/webhook), then mark complete
 *
 * External publishers can hook:
 * - filter `{$prefix}_outbox_publish_external` (bool $handled, OutboxEntry $entry, array $wrapped_payload)
 *
 * Transport can be overridden by:
 * - filter `{$prefix}_outbox_transport_for_entry` (string $transport, OutboxEntry $entry)
 */
final class RoutingOutboxPublisher implements IOutboxPublisher {

  public function __construct(
    private readonly IDDDConfig $config,
    private readonly OutboxConfig $outbox_config,
    private readonly ActionSchedulerOutboxPublisher $action_scheduler,
  ) {}

  public function publish(OutboxEntry $entry, array $wrapped_payload): void {
    $prefix = $this->config->prefix();

    $transport = apply_filters(
      "{$prefix}_outbox_transport_for_entry",
      $entry->transport,
      $entry
    );

    // Optional safety rail: if payload is huge, prefer external.
    $payload_bytes = $entry->payload_bytes > 0
      ? $entry->payload_bytes
      : strlen(wp_json_encode($wrapped_payload, JSON_UNESCAPED_SLASHES));

    $should_route_external = (
      $transport === 'external' ||
      (
        $this->outbox_config->route_large_payloads_to_external
        && $payload_bytes > $this->outbox_config->max_action_scheduler_payload_bytes
      )
    );

    if (!$should_route_external) {
      $this->action_scheduler->publish($entry, $wrapped_payload);
      return;
    }

    $handled = (bool) apply_filters(
      "{$prefix}_outbox_publish_external",
      false,
      $entry,
      $wrapped_payload
    );

    if ($handled) {
      return;
    }

    // If explicitly external, fail fast so retries/DLQ can surface misconfig.
    if ($transport === 'external') {
      throw new RuntimeException(
        'Outbox entry requires external transport, but no external publisher handled it.'
      );
    }

    // Otherwise, fall back to Action Scheduler (backwards compatible).
    $this->action_scheduler->publish($entry, $wrapped_payload);
  }
}


