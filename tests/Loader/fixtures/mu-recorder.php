<?php
/**
 * Must-use plugin for the loader fixtures: loads before every regular plugin
 * and records PHP diagnostics and the registry state along plugins_loaded.
 */

$GLOBALS['fx_loader_trace'] = [];
$GLOBALS['fx_loader_errors'] = [];

set_error_handler(static function (int $no, string $msg, string $file = '', int $line = 0): bool {
    $GLOBALS['fx_loader_errors'][] = ['level' => $no, 'message' => $msg, 'file' => $file, 'line' => $line];
    return false; // keep PHP's own handling
});

foreach ([0, 1, 2, 20, 30, PHP_INT_MAX] as $priority) {
    add_action('plugins_loaded', static function () use ($priority): void {
        $versions = class_exists('Tangible_DDD_Versions', false) ? Tangible_DDD_Versions::instance() : null;
        $GLOBALS['fx_loader_trace'][] = [
            'event' => 'plugins_loaded',
            'priority' => $priority === PHP_INT_MAX ? 'last' : $priority,
            'registered' => $versions ? array_keys($versions->all_registered()) : null,
            'initialized' => $versions ? $versions->is_initialized() : null,
        ];
    }, $priority, 0);
}
