<?php
/**
 * Snapshot of the loader outcome after a full WordPress boot, printed as
 * JSON. Run with `wp eval-file`; mu-recorder.php must be installed.
 * Absolute paths are reduced to "<copy>:<relative path>" so the recording is
 * stable across machines. A copy is a vendored tangible/ddd
 * (`copy:<plugin label>`) or the plugin-activated distribution
 * (`copy:P`, wp-content/plugins/tangible-ddd).
 *
 * Besides the registry state it records which files each copy contributed,
 * the winner's load diagnostics, the [tangible-ddd] lines of the PHP error
 * log, and a command -> domain event -> listener round trip on whatever
 * runtime won (register 7.2 load.new-alone).
 */

$normalize = static function (?string $path): ?string {
    if ($path === null || $path === '') {
        return $path;
    }
    $patterns = [
        '#^.*/wp-content/plugins/fx-([^/]+)/vendor/tangible/ddd(?:/|$)#' => 'copy:$1:',
        '#^.*/wp-content/plugins/tangible-ddd(?:/|$)#' => 'copy:P:',
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

// ── Command -> domain event -> listener round trip on the winning runtime ────
// Runs first, so its classes count in the origin checks below. Uses only API
// that is identical from 0.6.2 to 0.7.0 (EventsUnitOfWork, EventRouter,
// DomainEventsPublishMiddleware, WordPressEventDispatcher,
// WordPressActionHandler), so it also runs when a legacy copy won.
$round_trip = ['result' => null, 'heard' => [], 'error' => null];
try {
    if (!class_exists('FxRoundTrip_Pinged', false)) {
        eval(<<<'PHP'
            final class FxRoundTrip_Ping { public function __construct(public readonly string $who) {} }
            final class FxRoundTrip_Pinged extends \TangibleDDD\Domain\Events\DomainEvent {
                public function __construct(public readonly string $who) {}
                protected static function prefix(): string { return 'fxrt'; }
                public static function name(): string { return 'pinged'; }
                public function payload(): array { return ['who' => $this->who]; }
            }
            final class FxRoundTrip_Listener extends \TangibleDDD\Application\EventHandlers\WordPressActionHandler {
                protected function get_event_class(): string { return FxRoundTrip_Pinged::class; }
                public function handle(\TangibleDDD\Domain\Events\IDomainEvent $event): void { $GLOBALS['fx_round_trip_heard'][] = $event->who; }
            }
            final class FxRoundTrip_NoIntegration implements \TangibleDDD\Application\Events\IIntegrationEventBus {
                public function publish(\TangibleDDD\Domain\Events\IIntegrationEvent $event): void {}
            }
            final class FxRoundTrip_Handler implements \League\Tactician\Middleware {
                public function __construct(private \TangibleDDD\Application\Events\EventsUnitOfWork $uow) {}
                public function execute($command, callable $next) { $this->uow->record(new FxRoundTrip_Pinged($command->who)); return 'handled:' . $command->who; }
            }
            PHP);
    }
    $GLOBALS['fx_round_trip_heard'] = [];
    $uow = new \TangibleDDD\Application\Events\EventsUnitOfWork();
    $router = new \TangibleDDD\Application\Events\EventRouter(
        new \TangibleDDD\Infra\Services\WordPressEventDispatcher(),
        new FxRoundTrip_NoIntegration()
    );
    new FxRoundTrip_Listener();
    $bus = new \League\Tactician\CommandBus(
        new \TangibleDDD\Application\Events\DomainEventsPublishMiddleware($uow, $router),
        new FxRoundTrip_Handler($uow)
    );
    $round_trip['result'] = $bus->handle(new FxRoundTrip_Ping('alice'));
    $round_trip['heard'] = $GLOBALS['fx_round_trip_heard'];
} catch (\Throwable $e) {
    $round_trip['error'] = get_class($e) . ': ' . $normalize_text($e->getMessage());
}

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
    // the round trip's classes
    'TangibleDDD\\Application\\Events\\EventsUnitOfWork',
    'TangibleDDD\\Application\\Events\\EventRouter',
    'TangibleDDD\\Application\\Events\\DomainEventsPublishMiddleware',
    'TangibleDDD\\Infra\\Services\\WordPressEventDispatcher',
    'TangibleDDD\\Application\\EventHandlers\\WordPressActionHandler',
] as $class) {
    $class_origin[$class] = class_exists($class) || interface_exists($class)
        ? $copy_of($normalize((new ReflectionClass($class))->getFileName() ?: null))
        : 'absent';
}

$function_origin = [];
foreach ([
    'tangible_ddd_self_consume',
    'TangibleDDD\\WordPress\\install_tables',
    'TangibleDDD\\WordPress\\boot',
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

// Files per copy. A copy that did not win should contribute only its loader
// entry (and nothing at all when Composer deduped it); anything else means
// its code runs mixed into the winner's runtime.
$loader_files = [];
$copy_files = [];
foreach (get_included_files() as $f) {
    $n = (string) $normalize($f);
    if (!preg_match('#^copy:([^:]+):(.*)$#', $n, $m)) {
        continue;
    }
    [, $copy, $rel] = $m;
    if ($rel === 'tangible-ddd.php' || preg_match('#^loader/tangible-ddd-\d+_\d+_\d+\.php$#', $rel)) {
        $loader_files[] = $copy . ($rel === 'tangible-ddd.php' ? '' : ':' . $rel);
    }
    $copy_files[$copy][] = $rel;
}
$copy_file_counts = array_map('count', $copy_files);
ksort($copy_file_counts);

$winner = $versions?->winner();
$registered = [];
foreach ($versions?->all_registered() ?? [] as $v => $path) {
    $registered[$v] = $copy_of($normalize($path));
}

// Composer "files" entries every vendored 0.7 copy runs at include time,
// whether it wins or not: the version-unique loader entry (and the
// tangible-ddd.php it forwards to) and the guarded assert helper (CR-SM-4).
$non_loader_files = [];
foreach ($copy_files as $copy => $files) {
    $non_loader_files[$copy] = array_values(array_filter(
        $files,
        static fn(string $rel): bool => $rel !== 'tangible-ddd.php'
            && !preg_match('#^loader/tangible-ddd-\d+_\d+_\d+\.php$#', $rel)
            && $rel !== 'packages/ddd-core/src/Domain/Shared/assert.php'
    ));
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

$diagnostics = null;
if (class_exists('Tangible_DDD_Load_Diagnostics', false)) {
    $diagnostics = array_map(
        static fn(array $f): array => ['code' => $f['code'], 'message' => $normalize_text($f['message'])],
        Tangible_DDD_Load_Diagnostics::findings()
    );
}

$error_log = [];
$log_file = (string) ini_get('error_log');
if ($log_file !== '' && is_file($log_file)) {
    foreach (file($log_file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (str_contains($line, '[tangible-ddd]')) {
            $error_log[] = $normalize_text((string) preg_replace('/^\[[^\]]+\] /', '', $line));
        }
    }
}

$framework_version = null;
if (class_exists('TangibleDDD\\Infra\\Config')) {
    try {
        $framework_version = \TangibleDDD\Infra\Config::for_wordpress()->version();
    } catch (\Throwable $e) {
        $framework_version = 'error:' . get_class($e);
    }
}

echo json_encode([
    'active_plugins' => array_values((array) get_option('active_plugins')),
    'loader_files_included' => $loader_files,
    'version_constant' => defined('TANGIBLE_DDD_VERSION') ? TANGIBLE_DDD_VERSION : null,
    'framework_version' => $framework_version,
    'registered' => $registered,
    'winner' => $winner ? ['version' => $winner['version'], 'copy' => $copy_of($normalize($winner['path']))] : null,
    'initialized' => $versions?->is_initialized(),
    'unmet_minimums' => $versions?->unmet_minimums(),
    'slugged_functions' => $slugged,
    'class_origin' => $class_origin,
    'function_origin' => $function_origin,
    'self_consumer' => $self_consumer,
    'wp_ddd_command' => $wp_ddd_command,
    'copy_file_counts' => $copy_file_counts,
    'copy_non_loader_files' => $non_loader_files,
    'round_trip' => $round_trip,
    'diagnostics' => $diagnostics,
    'error_log' => $error_log,
    'trace' => $GLOBALS['fx_loader_trace'] ?? [],
    'php_diagnostics' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
