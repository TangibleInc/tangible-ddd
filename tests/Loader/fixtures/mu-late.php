<?php
/**
 * Must-use plugin for register 7.2 load.late: loads the new copy's vendor
 * autoloader at `init`, after plugins_loaded has fired (theme or activation
 * request shape). The fx-new plugin itself is not active in this case.
 */

add_action('init', static function (): void {
    $GLOBALS['fx_loader_trace'][] = ['event' => 'late-include', 'plugin' => 'new'];
    require WP_PLUGIN_DIR . '/fx-new/vendor/autoload.php';
}, 1, 0);
