<?php

declare(strict_types=1);

namespace TangibleDDD\WordPress\Adapter;

use TangibleDDD\Application\Outbox\OutboxConfig;
use TangibleDDD\Infra\IDDDConfig;
use TangibleDDD\Runtime\Outbox\IOutboxOptionsReader;

/**
 * The wp IOutboxOptionsReader (X11): the 0.6 OutboxConfig::from_options()
 * body, unchanged (same option names, same defaults, the consumer's
 * `outbox` Action Scheduler group).
 */
final class WpOptionsOutboxConfigReader implements IOutboxOptionsReader {

  public function read(IDDDConfig $config): OutboxConfig {
    return new OutboxConfig(
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
