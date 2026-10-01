<?php

declare(strict_types=1);

namespace Tangible\Datastream\Infra;

use TangibleDDD\Infra\IDDDConfig;

/**
 * DDD framework configuration for tangible-datastream v3.
 *
 * The $wp_table_prefix constructor arg exists solely for unit-testability:
 * in production, pass global $wpdb->prefix; in unit tests, pass 'wp_' (the
 * default) or any stub prefix. This avoids requiring a WP bootstrap in
 * pure-PHP tests.
 *
 * Table naming follows the framework convention:
 *   {wp_table_prefix}{plugin_prefix}_{bare_name}
 * e.g. wp_tangible_datastream_subscriptions
 *
 * The design doc's "tds_*" shorthand refers to {plugin_prefix}_{bare_name}
 * without the WP prefix — not a distinct plugin-level prefix.
 *
 * Note on repositories: The composer.json path repository (../../wp-content/plugins/tangible-ddd)
 * is for local development only. CI/prod resolves tangible/ddd via Satispress or VCS.
 */
final class DatastreamConfig implements IDDDConfig {

  /**
   * @param string $wp_table_prefix  The WordPress table prefix (e.g. $wpdb->prefix).
   *                                 Defaults to 'wp_' for unit-test convenience.
   */
  public function __construct(
    private readonly string $wp_table_prefix = 'wp_',
  ) {}

  /**
   * DI container factory: creates a DatastreamConfig using the live $wpdb->prefix.
   *
   * Wire via services.yaml factory: ['Tangible\Datastream\Infra\DatastreamConfig', 'for_wordpress']
   * This keeps the constructor prefix-injectable for pure unit tests.
   */
  public static function for_wordpress(): self {
    global $wpdb;
    return new self($wpdb->prefix);
  }

  public function prefix(): string {
    return 'tangible_datastream';
  }

  /**
   * Returns the full WP table name for a bare table name.
   *
   * Known tables (doc §2):
   *   subscriptions, destinations, deliveries, integration_outbox,
   *   integration_dlq, delivery_log, long_processes, behaviour_workflows
   *
   * @param string $name  e.g. 'integration_outbox'
   * @return string       e.g. 'wp_tangible_datastream_integration_outbox'
   */
  public function table(string $name): string {
    return $this->wp_table_prefix . $this->prefix() . '_' . $name;
  }

  /**
   * @param string $name  e.g. 'process_continue'
   * @return string       e.g. 'tangible_datastream_process_continue'
   */
  public function hook(string $name): string {
    return $this->prefix() . '_' . $name;
  }

  /**
   * ActionScheduler group name (hyphen-separated per framework convention).
   *
   * @param string $name  e.g. 'outbox'
   * @return string       e.g. 'tangible-datastream-outbox'
   */
  public function as_group(string $name): string {
    return str_replace('_', '-', $this->prefix()) . '-' . $name;
  }

  /**
   * @param string $name  e.g. 'outbox_batch_size'
   * @return string       e.g. 'tangible_datastream_outbox_batch_size'
   */
  public function option(string $name): string {
    return $this->prefix() . '_' . $name;
  }

  /**
   * WordPress action name for a domain event.
   * Matches DomainEvent::action(): {prefix}_domain_{event_name}
   *
   * @param string $event_name  e.g. 'event_captured'
   * @return string             e.g. 'tangible_datastream_domain_event_captured'
   */
  public function domain_action(string $event_name): string {
    return $this->prefix() . '_domain_' . $event_name;
  }

  /**
   * WordPress action name for an integration event.
   * Matches IntegrationEvent::integration_action(): {prefix}_integration_{event_name}
   *
   * @param string $event_name  e.g. 'event_ready_for_delivery'
   * @return string             e.g. 'tangible_datastream_integration_event_ready_for_delivery'
   */
  public function integration_action(string $event_name): string {
    return $this->prefix() . '_integration_' . $event_name;
  }

  public function version(): string {
    return '3.0.0-dev';
  }
}
