<?php
/**
 * The consumer Config every compiled-container fixture constructs
 * (`new \Tangible\LMS\Infra\Config('0.12.0')` in the shipped container,
 * renamed into FxCompiled\...\Consumer\Infra\Config). It implements the
 * eight IDDDConfig methods exactly as the shipped consumers do on 0.6.5
 * (report D section 4.1), with `table()` over `$wpdb->prefix`, so it also
 * proves a 0.6.5-shaped IDDDConfig still satisfies the winner's interface.
 */

namespace FxCompiled\Support;

abstract class FixtureConfig implements \TangibleDDD\Infra\IDDDConfig
{
    public const PREFIX = 'fxcc';

    public function __construct(private readonly string $version = '0.0.0')
    {
    }

    public function prefix(): string
    {
        return static::PREFIX;
    }

    public function table(string $name): string
    {
        global $wpdb;

        return $wpdb->prefix . static::PREFIX . '_' . $name;
    }

    public function hook(string $name): string
    {
        return static::PREFIX . '_' . $name;
    }

    public function as_group(string $name): string
    {
        return static::PREFIX . '-' . $name;
    }

    public function option(string $name): string
    {
        return static::PREFIX . '_' . $name;
    }

    public function domain_action(string $event_name): string
    {
        return static::PREFIX . '_domain_' . $event_name;
    }

    public function integration_action(string $event_name): string
    {
        return static::PREFIX . '_integration_' . $event_name;
    }

    public function version(): string
    {
        return $this->version;
    }
}
