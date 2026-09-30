<?php
/**
 * Plugin Name: DDD loader fixture __LABEL__
 * Description: Bundles tangible/ddd __VERSION__ in its own vendor/, the way a consumer plugin does (report F section 6).
 * Version: __VERSION__
 */

// Include-time: the consumer's Composer autoloader runs tangible/ddd's
// "files" entry (tangible-ddd.php), unless Composer's cross-vendor dedup has
// already seen that file identifier (B1).
$GLOBALS['fx_loader_trace'][] = ['event' => 'include', 'plugin' => '__LABEL__'];
require __DIR__ . '/vendor/autoload.php';

// A consumer declares the minimum ddd it needs (Tangible_DDD_Versions API).
add_action('plugins_loaded', static function (): void {
    if (class_exists('Tangible_DDD_Versions', false)) {
        Tangible_DDD_Versions::instance()->require_version('fx-__LABEL__', '__VERSION__');
    }
}, 5, 0);
