<?php
/**
 * Snapshot of the loader outcome after a full WordPress boot, printed as
 * JSON. Run with `wp eval-file`; mu-recorder.php must be installed.
 * Absolute paths are reduced to "<copy>:<relative path>" so the recording is
 * stable across machines.
 */

$normalize = static function (?string $path): ?string {
    if ($path === null || $path === '') {
        return $path;
    }
    $patterns = [
        '#^.*/wp-content/plugins/fx-([^/]+)/vendor/tangible/ddd(?:/|$)#' => 'copy:$1:',
        '#^.*/wp-content/plugins/fx-([^/]+)/#' => 'plugin:$1:',
        '#^.*/wp-content/mu-plugins/#' => 'mu:',
        '#^/var/www/html/#' => 'wp:',
    ];
    foreach ($patterns as $re => $to) {
        $out = preg_replace($re, $to, $path, 1, $n);
        if ($n > 0) {
            return rtrim((string) $out, ':');
        }
    }

    return $path;
};
$normalize_text = static fn(string $s): string => (string) preg_replace_callback(
    '#/var/www/html/[^\s\'"()]+#',
    static fn(array $m): string => (string) $normalize($m[0]),
    $s
);
$copy_of = static function (?string $normalized): ?string {
    return ($normalized !== null && preg_match('#^copy:([^:]+)#', $normalized, $m)) ? $m[1] : $normalized;
};

$versions = class_exists('Tangible_DDD_Versions', false) ? Tangible_DDD_Versions::instance() : null;

$class_origin = [];
foreach ([
    'Tangible_DDD_Versions',
    'TangibleDDD\\Application\\Process\\ProcessRunner',
    'TangibleDDD\\Application\\Outbox\\OutboxConfig',
    'TangibleDDD\\Infra\\Consumers\\ConsumerRegistry',
    'TangibleDDD\\Infra\\Persistence\\OutboxRepository',
    'TangibleDDD\\Infra\\Services\\ActionSchedulerOutboxPublisher',
    'TangibleDDD\\WordPress\\Admin\\Dashboard\\AdminPage',
] as $class) {
    $class_origin[$class] = class_exists($class) || interface_exists($class)
        ? $copy_of($normalize((new ReflectionClass($class))->getFileName() ?: null))
        : 'absent';
}

$function_origin = [];
foreach ([
    'tangible_ddd_self_consume',
    'TangibleDDD\\WordPress\\install_tables',
    'TangibleDDD\\WordPress\\SelfConsumer\\di',
] as $fn) {
    $function_origin[$fn] = function_exists($fn)
        ? $copy_of($normalize((new ReflectionFunction($fn))->getFileName() ?: null))
        : 'absent';
}

$slugged = [];
foreach (get_defined_functions()['user'] as $fn) {
    if (preg_match('/^tangible_ddd_(register|initialize)_(\d+_\d+_\d+)$/', $fn, $m)) {
        $slugged[] = $fn;
    }
}
sort($slugged);

$self_consumer = 'absent';
if (function_exists('TangibleDDD\\WordPress\\SelfConsumer\\di')) {
    try {
        $c = \TangibleDDD\WordPress\SelfConsumer\di();
        $self_consumer = is_object($c) ? 'built:' . get_class($c) : 'null';
    } catch (\Throwable $e) {
        $self_consumer = 'error:' . get_class($e);
    }
}

$wp_ddd_command = class_exists('WP_CLI', false)
    && isset(\WP_CLI::get_root_command()->get_subcommands()['ddd']);

$loader_files = [];
foreach (get_included_files() as $f) {
    if (preg_match('#/vendor/tangible/ddd/tangible-ddd\.php$#', $f)) {
        $loader_files[] = $copy_of($normalize($f));
    }
}

$winner = $versions?->winner();
$registered = [];
foreach ($versions?->all_registered() ?? [] as $v => $path) {
    $registered[$v] = $copy_of($normalize($path));
}

$errors = [];
foreach ($GLOBALS['fx_loader_errors'] ?? [] as $e) {
    if (str_starts_with($e['file'], 'phar://')) {
        continue; // wp-cli's own bundled dependencies, not the code under test
    }
    $errors[] = [
        'level' => $e['level'],
        'message' => $normalize_text($e['message']),
        'file' => $normalize($e['file']),
    ];
}

echo json_encode([
    'active_plugins' => array_values((array) get_option('active_plugins')),
    'loader_files_included' => $loader_files,
    'version_constant' => defined('TANGIBLE_DDD_VERSION') ? TANGIBLE_DDD_VERSION : null,
    'registered' => $registered,
    'winner' => $winner ? ['version' => $winner['version'], 'copy' => $copy_of($normalize($winner['path']))] : null,
    'initialized' => $versions?->is_initialized(),
    'unmet_minimums' => $versions?->unmet_minimums(),
    'slugged_functions' => $slugged,
    'class_origin' => $class_origin,
    'function_origin' => $function_origin,
    'self_consumer' => $self_consumer,
    'wp_ddd_command' => $wp_ddd_command,
    'trace' => $GLOBALS['fx_loader_trace'] ?? [],
    'php_diagnostics' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
