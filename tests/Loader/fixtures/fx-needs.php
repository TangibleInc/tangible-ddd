<?php
/**
 * Plugin Name: DDD loader fixture needs-99
 * Description: A consumer that requires a tangible-ddd above any winner (register 7.2 load.min-unmet). Bundles no copy.
 * Version: 1.0.0
 */

add_action('plugins_loaded', static function (): void {
    if (class_exists('Tangible_DDD_Versions', false)) {
        Tangible_DDD_Versions::instance()->require_version('fx-needs-99', '99.0.0');
    }
}, 5, 0);
