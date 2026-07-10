<?php

declare(strict_types=1);

namespace TangibleDDD\Infra;

/**
 * Self-consumer config: tangible-ddd hosting the DDD stack for ITS OWN
 * operational commands (replay / discard / retry / purge). Prefix 'tangible_ddd'
 * → its own six tables (wp_tangible_ddd_*), so framework actions self-audit into
 * wp_tangible_ddd_command_audit. Mirrors the consumer Config pattern
 * (see tangible-datastream DatastreamConfig / tangible-cred Config).
 */
final class Config implements IDDDConfig {

    public function __construct(
        private readonly string $wp_table_prefix = 'wp_',
    ) {}

    /** DI factory: live $wpdb->prefix in production; injectable for tests. */
    public static function for_wordpress(): self {
        global $wpdb;
        return new self($wpdb->prefix);
    }

    public function prefix(): string {
        return 'tangible_ddd';
    }

    public function table(string $name): string {
        return $this->wp_table_prefix . $this->prefix() . '_' . $name;
    }

    public function hook(string $name): string {
        return $this->prefix() . '_' . $name;
    }

    public function as_group(string $name): string {
        return str_replace('_', '-', $this->prefix()) . '-' . $name;
    }

    public function option(string $name): string {
        return $this->prefix() . '_' . $name;
    }

    public function domain_action(string $event_name): string {
        return $this->prefix() . '_domain_' . $event_name;
    }

    public function integration_action(string $event_name): string {
        return $this->prefix() . '_integration_' . $event_name;
    }

    public function version(): string {
        return defined('TANGIBLE_DDD_VERSION') ? TANGIBLE_DDD_VERSION : '0.2.0-dev';
    }
}
