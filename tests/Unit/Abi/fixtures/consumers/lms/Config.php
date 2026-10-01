<?php

declare(strict_types=1);

namespace Tangible\LMS\Infra;

use TangibleDDD\Infra\IDDDConfig;

/**
 * Configuration for the LMS plugin's DDD infrastructure.
 *
 * Provides prefixed names for tables, hooks, options, and events.
 */
class Config implements IDDDConfig {
    private const PREFIX = 'tangible_lms';

    public function __construct(
        private readonly string $version,
    ) {
    }

    public function prefix(): string {
        return self::PREFIX;
    }

    public function table(string $name): string {
        global $wpdb;

        return $wpdb->prefix.self::PREFIX.'_'.$name;
    }

    public function hook(string $name): string {
        return self::PREFIX.'_'.$name;
    }

    public function as_group(string $name): string {
        return str_replace('_', '-', self::PREFIX).'-'.$name;
    }

    public function option(string $name): string {
        return self::PREFIX.'_'.$name;
    }

    public function domain_action(string $event_name): string {
        return self::PREFIX.'_domain_'.$event_name;
    }

    public function integration_action(string $event_name): string {
        return self::PREFIX.'_integration_'.$event_name;
    }

    public function version(): string {
        return $this->version;
    }
}
