<?php

namespace TangibleDDD\Infra;

/**
 * Configuration interface for DDD framework consumers.
 *
 * Each consuming plugin generates a Config class implementing this interface
 * with their specific prefix. All framework services depend on this interface.
 */
interface IDDDConfig {
  /**
   * The plugin prefix (e.g., 'tgbl_cred').
   */
  public function prefix(): string;

  /**
   * Generate a prefixed table name.
   *
   * @param string $name Base table name (e.g., 'integration_outbox')
   * @return string Full table name with WP prefix (e.g., 'wp_tgbl_cred_integration_outbox')
   */
  public function table(string $name): string;

  /**
   * Generate a prefixed hook/action name.
   *
   * @param string $name Base hook name (e.g., 'process_continue')
   * @return string Prefixed hook name (e.g., 'tgbl_cred_process_continue')
   */
  public function hook(string $name): string;

  /**
   * Generate a prefixed ActionScheduler group name.
   *
   * @param string $name Base group name (e.g., 'outbox')
   * @return string Prefixed group name (e.g., 'tgbl-cred-outbox')
   */
  public function as_group(string $name): string;

  /**
   * Generate a prefixed option name.
   *
   * @param string $name Base option name (e.g., 'outbox_batch_size')
   * @return string Prefixed option name (e.g., 'tgbl_cred_outbox_batch_size')
   */
  public function option(string $name): string;

  /**
   * Generate a domain event action name.
   *
   * @param string $event_name Event name (e.g., 'user_earned')
   * @return string Full action name (e.g., 'tgbl_cred_domain_user_earned')
   */
  public function domain_action(string $event_name): string;

  /**
   * Generate an integration event action name.
   *
   * @param string $event_name Event name (e.g., 'user_earned')
   * @return string Full action name (e.g., 'tgbl_cred_integration_user_earned')
   */
  public function integration_action(string $event_name): string;

  /**
   * Get the plugin version string.
   *
   * @return string Version (e.g., '1.0.0' or 'dev')
   */
  public function version(): string;
}
