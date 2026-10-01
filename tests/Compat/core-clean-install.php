<?php
/**
 * Runtime half of tests/Compat/core-clean-install.sh (register 1.5 and the
 * wave-2 acceptance): run inside a fresh Composer project that requires only
 * tangible/ddd-core. Prints one line per check and exits non-zero on any
 * failure.
 *
 *   php core-clean-install.php <project-dir>
 */

declare(strict_types=1);

// Everything runs in a function scope, so the script's own variables are not
// mistaken for globals that autoload introduced.
exit((static function (array $argv): int {

$project = $argv[1] ?? getcwd();
$failures = 0;
$check = static function (bool $ok, string $what, string $detail = '') use (&$failures): void {
    printf("%s %s%s\n", $ok ? 'ok  ' : 'FAIL', $what, $ok || $detail === '' ? '' : ' -- ' . $detail);
    if (!$ok) {
        $failures++;
    }
};

// Snapshot before autoload, so only what Composer and ddd-core add is judged.
$functions_before = get_defined_functions()['user'];
$constants_before = array_keys(get_defined_constants(true)['user'] ?? []);
$globals_before = array_keys($GLOBALS);
$loaders_before = count(spl_autoload_functions());

require $project . '/vendor/autoload.php';

$wp_symbols = static function (): array {
    $found = [];
    foreach (['add_action', 'add_filter', 'do_action', 'apply_filters', 'get_option', 'update_option',
        'wp_json_encode', 'is_multisite', 'get_current_blog_id', 'as_schedule_single_action', 'esc_html', '__',
        'TangibleDDD\\WordPress\\install_tables', 'TangibleDDD\\WordPress\\boot'] as $fn) {
        if (function_exists($fn)) {
            $found[] = "function {$fn}";
        }
    }
    foreach (['Tangible_DDD_Versions', 'Tangible_DDD_Winner_Autoloader', 'wpdb', 'WP_Error', 'WP_CLI', 'ActionScheduler'] as $class) {
        if (class_exists($class, false)) {
            $found[] = "class {$class}";
        }
    }
    foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $class) {
        if (str_starts_with($class, 'TangibleDDD\\WordPress\\') || str_starts_with($class, 'WP_') || str_starts_with($class, 'ActionScheduler')) {
            $found[] = "class {$class}";
        }
    }
    foreach (['TANGIBLE_DDD_VERSION', 'ABSPATH', 'WP_DEBUG', 'WPINC', 'DDD_SCHEMA_VERSION'] as $const) {
        if (defined($const)) {
            $found[] = "constant {$const}";
        }
    }
    foreach (['wpdb', 'wp_filter', 'wp_actions'] as $global) {
        if (array_key_exists($global, $GLOBALS)) {
            $found[] = "global \${$global}";
        }
    }

    return array_values(array_unique($found));
};

$found = $wp_symbols();
$check($found === [], 'no WordPress function, class, constant or global after autoload', implode(', ', $found));

$new_functions = array_values(array_diff(get_defined_functions()['user'], $functions_before));
$check(
    $new_functions === ['tangibleddd\\domain\\shared\\assert_type'],
    'the only function autoload defines is TangibleDDD\\Domain\\Shared\\assert_type (core files entry)',
    json_encode($new_functions)
);

$new_constants = array_values(array_diff(array_keys(get_defined_constants(true)['user'] ?? []), $constants_before));
$check($new_constants === [], 'autoload defines no constant', json_encode($new_constants));

// __composer_autoload_files is Composer's own files-entry ledger.
$new_globals = array_values(array_diff(array_keys($GLOBALS), $globals_before, ['__composer_autoload_files']));
$check($new_globals === [], 'autoload adds no global variable', json_encode($new_globals));

$loaders = spl_autoload_functions();
$only_composer = count($loaders) === $loaders_before + 1
    && is_array(end($loaders)) && end($loaders)[0] instanceof \Composer\Autoload\ClassLoader;
$check($only_composer, 'autoload registers exactly one autoloader, Composer\'s', (string) count($loaders));

// Declare every class ddd-core ships. A class whose declaration needs a
// WordPress or other non-closure symbol fails here; the DI bridge needs
// symfony/dependency-injection, which core only suggests (X4).
$src = $project . '/vendor/tangible/ddd-core/src';
$bridge = 'TangibleDDD\\Infra\\DependencyInjection\\';
$declared = 0;
$skipped_bridge = 0;
$broken = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($it as $file) {
    if ($file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);
foreach ($files as $path) {
    $rel = substr($path, strlen($src) + 1);
    if ($rel === 'Domain/Shared/assert.php') {
        continue;
    }
    $fqcn = 'TangibleDDD\\' . str_replace('/', '\\', substr($rel, 0, -4));
    if (str_starts_with($fqcn, $bridge) && !interface_exists('Symfony\\Component\\DependencyInjection\\Compiler\\CompilerPassInterface')) {
        $skipped_bridge++;
        continue;
    }
    try {
        if (class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn) || enum_exists($fqcn)) {
            $declared++;
        } else {
            $broken[] = "{$fqcn}: file declares no such symbol";
        }
    } catch (\Throwable $e) {
        $broken[] = "{$fqcn}: " . get_class($e) . ': ' . $e->getMessage();
    }
}
// A class whose missing parent still lives in packages/ddd-wp/src is a
// split the wave-2 move table defers to round 2 ("split-deferred"): its core
// half has not landed yet. Reported as PENDING, a failure only at the gate.
$wp_src = getenv('DDD_WP_SRC') ?: '';
$gate = getenv('DDD_GATE') === '1';
$pending = [];
$hard = [];
foreach ($broken as $line) {
    if ($wp_src !== '' && preg_match('/(?:Class|Interface|Trait) "(TangibleDDD\\\\[^"]+)" not found/', $line, $m)
        && is_file($wp_src . '/' . str_replace('\\', '/', substr($m[1], strlen('TangibleDDD\\'))) . '.php')) {
        $pending[] = $line . ' (still in packages/ddd-wp/src)';
    } else {
        $hard[] = $line;
    }
}
$check($hard === [], sprintf('every ddd-core class declares without WordPress (%d declared, %d bridge skipped, %d pending)', $declared, $skipped_bridge, count($pending)), "\n  " . implode("\n  ", $hard));
if ($pending !== []) {
    if ($gate) {
        $check(false, 'no ddd-core class depends on a split-deferred class still in ddd-wp (DDD_GATE=1)', "\n  " . implode("\n  ", $pending));
    } else {
        printf("PENDING %d ddd-core classes extend a split-deferred class still in ddd-wp (round 2); DDD_GATE=1 fails on them:\n  %s\n", count($pending), implode("\n  ", $pending));
    }
}

$found = $wp_symbols();
$check($found === [], 'still no WordPress symbol after declaring every core class', implode(', ', $found));

return $failures === 0 ? 0 : 1;

})($argv));
