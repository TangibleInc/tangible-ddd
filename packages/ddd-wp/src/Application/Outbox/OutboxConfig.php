<?php

namespace TangibleDDD\Application\Outbox;

use TangibleDDD\Infra\IDDDConfig;

/**
 * Configuration for the transactional outbox.
 */
final class OutboxConfig {

  public function __construct(
    public readonly int $batch_size = 50,
    public readonly int $max_attempts = 5,
    public readonly int $base_retry_delay_seconds = 60,
    public readonly float $retry_multiplier = 2.0,
    public readonly int $max_retry_delay_seconds = 3600,
    public readonly int $processor_interval_seconds = 30,
    public readonly int $lock_timeout_seconds = 300,
    public readonly string $action_scheduler_group = 'ddd-outbox',
    public readonly int $max_action_scheduler_payload_bytes = 50000,
    public readonly bool $route_large_payloads_to_external = false,
  ) {}

  /**
   * Create config from WordPress options using plugin config for prefixes.
   */
  public static function from_options(IDDDConfig $config): self {
    return new self(
      batch_size: (int) get_option($config->option('outbox_batch_size'), 50),
      max_attempts: (int) get_option($config->option('outbox_max_attempts'), 5),
      base_retry_delay_seconds: (int) get_option($config->option('outbox_retry_delay'), 60),
      retry_multiplier: (float) get_option($config->option('outbox_retry_multiplier'), 2.0),
      max_retry_delay_seconds: (int) get_option($config->option('outbox_max_retry_delay'), 3600),
      processor_interval_seconds: (int) get_option($config->option('outbox_processor_interval'), 30),
      lock_timeout_seconds: (int) get_option($config->option('outbox_lock_timeout'), 300),
      action_scheduler_group: $config->as_group('outbox'),
      max_action_scheduler_payload_bytes: (int) get_option($config->option('outbox_max_as_payload_bytes'), 50000),
      route_large_payloads_to_external: (bool) get_option($config->option('outbox_route_large_payloads_external'), false),
    );
  }
}
