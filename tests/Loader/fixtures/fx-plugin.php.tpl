<?php
/**
 * Plugin Name: DDD loader fixture __LABEL__
 * Description: Bundles tangible/ddd __VERSION__ in its own vendor/, the way a consumer plugin does (report F section 6).
 * Version: __VERSION__
 */

// Include-time: the consumer's Composer autoloader runs tangible/ddd's
// "files" entry (tangible-ddd.php up to 0.6.x, loader/tangible-ddd-<slug>.php
// from 0.7.0), unless Composer's cross-vendor dedup has already seen that
// file identifier (B1). A Jetpack-autoloaded fixture (LMS, quiz) requires
// vendor/autoload_packages.php instead, as those plugins do (report D F13).
$GLOBALS['fx_loader_trace'][] = ['event' => 'include', 'plugin' => '__LABEL__'];
require __DIR__ . '/vendor/__AUTOLOAD__';

// __INCLUDE_TIME__

// A consumer declares the minimum ddd it needs (Tangible_DDD_Versions API).
add_action('plugins_loaded', static function (): void {
    if (class_exists('Tangible_DDD_Versions', false)) {
        Tangible_DDD_Versions::instance()->require_version('fx-__LABEL__', '__VERSION__');
    }
}, 5, 0);
