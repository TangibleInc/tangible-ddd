<?php
/**
 * Plugin Name: Tangible DDD
 * Plugin URI: https://tangible.one
 * Description: Domain-Driven Design framework for WordPress plugins
 * Version: 0.5.0
 * Author: Tangible
 * Author URI: https://tangible.one
 * License: MIT
 * Requires PHP: 8.1
 *
 * Version-negotiation loader — mirrors Action Scheduler's multi-copy coexistence
 * pattern so that multiple WordPress plugins can each bundle a copy of tangible-ddd
 * and only the newest copy initialises (newest-wins, loaded once).
 *
 * HOW IT WORKS
 * ─────────────
 * Every bundled copy of tangible-ddd has this file as its composer "files"
 * entry-point.  When vendor/autoload.php is required by any consumer, this file
 * runs and:
 *
 *   Priority 0  — registers THIS copy's version + path into Tangible_DDD_Versions.
 *   Priority 1  — Tangible_DDD_Versions::instance()->initialize_latest() picks the
 *                 highest registered version and runs its initializer exactly once.
 *                 The initializer prepends an spl_autoload for TangibleDDD\ classes
 *                 and require_once's all procedural ddd-wordpress/*.php files.
 *   Priority 20 — consumer plugins (e.g. tangible-datastream) boot against the
 *                 already-initialised framework.
 *
 * The Tangible_DDD_Versions class and both version-named functions are guarded by
 * function_exists / class_exists so that N copies of this file can be included
 * without any symbol collisions.
 */

declare(strict_types=1);

// ─── Version constant for THIS copy ──────────────────────────────────────────
// Guarded: the first copy to load wins the constant (oldest-loads-first is fine;
// the registry, not the constant, determines the winner).
if (!defined('TANGIBLE_DDD_VERSION')) {
    define('TANGIBLE_DDD_VERSION', '0.5.0');
}

// ─── Tangible_DDD_Versions registry (defined once, first copy wins the class) ─
if (!class_exists('Tangible_DDD_Versions', false)) {

    /**
     * Registry for all tangible-ddd copies present on a site.
     *
     * Mirrors ActionScheduler_Versions: every bundled copy registers itself at
     * plugins_loaded priority 0; the registry picks the winner at priority 1.
     */
    class Tangible_DDD_Versions
    {
        /** @var self|null */
        private static ?self $instance = null;

        /**
         * version => ['path' => string, 'callback' => callable]
         * @var array<string, array{path: string, callback: callable}>
         */
        private array $registrations = [];

        /**
         * Consumer min-version requirements, kept SEPARATE from copy
         * registrations so a consumer can never participate in winner selection.
         * consumer-name => minimum required ddd version.
         * @var array<string, string>
         */
        private array $requirements = [];

        /** True once initialize_latest() has executed the winner callback. */
        private bool $initialized = false;

        /** Version string of the winning copy, set after initialize_latest(). */
        private ?string $winner_version = null;

        /** Singleton accessor. */
        public static function instance(): self
        {
            if (self::$instance === null) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Register a copy of tangible-ddd.
         *
         * @param string      $version      SemVer string (e.g. '0.2.4').
         * @param string      $path         Absolute path to the ddd plugin root.
         * @param callable    $initialize   Callback that boots THIS copy: receives $path.
         * @param string|null $min_required Minimum ddd version this consumer needs.
         */
        public function register(
            string $version,
            string $path,
            callable $initialize
        ): void {
            // Same version registered twice → ignore the duplicate.
            if (isset($this->registrations[$version])) {
                return;
            }
            $this->registrations[$version] = [
                'path'     => $path,
                'callback' => $initialize,
            ];
        }

        /**
         * Declare that a CONSUMER plugin needs at least $min_required ddd.
         *
         * Recorded separately from copy registrations — a consumer is NOT a ddd
         * copy and must never enter winner selection (latest()/all_registered()).
         * Surfaced post-init via unmet_minimums() for health checks.
         *
         * @param string $consumer     Stable consumer identifier (e.g. plugin slug).
         * @param string $min_required Minimum ddd version the consumer needs.
         */
        public function require_version(string $consumer, string $min_required): void
        {
            $this->requirements[$consumer] = $min_required;
        }

        /**
         * Return the highest registered version string, or null if none registered.
         */
        public function latest(): ?string
        {
            if (empty($this->registrations)) {
                return null;
            }
            $versions = array_keys($this->registrations);
            usort($versions, 'version_compare');
            return end($versions);
        }

        /**
         * Call the winner's initializer exactly once.
         * Safe to hook at plugins_loaded and also to call directly in tests.
         */
        public function initialize_latest(): void
        {
            if ($this->initialized) {
                return;
            }
            $this->initialized = true;

            $winner = $this->latest();
            if ($winner === null) {
                return;
            }

            $this->winner_version = $winner;
            $reg = $this->registrations[$winner];
            ($reg['callback'])($reg['path']);
        }

        // ── Diagnostic accessors (for admin pages / health checks) ────────────

        /**
         * All registered copies: version => absolute path.
         * @return array<string, string>
         */
        public function all_registered(): array
        {
            $out = [];
            foreach ($this->registrations as $v => $r) {
                $out[$v] = $r['path'];
            }
            return $out;
        }

        /**
         * Winning copy info, or null before initialize_latest() runs.
         * @return array{version: string, path: string}|null
         */
        public function winner(): ?array
        {
            if ($this->winner_version === null) {
                return null;
            }
            return [
                'version' => $this->winner_version,
                'path'    => $this->registrations[$this->winner_version]['path'],
            ];
        }

        /**
         * Consumers whose required minimum exceeds the winning version.
         * Empty before initialize_latest() runs (no winner yet to judge against).
         * @return array<string, string>  consumer => min_required
         */
        public function unmet_minimums(): array
        {
            $winner = $this->winner_version;
            if ($winner === null) {
                return [];
            }
            $unmet = [];
            foreach ($this->requirements as $consumer => $min) {
                if (version_compare($min, $winner, '>')) {
                    $unmet[$consumer] = $min;
                }
            }
            return $unmet;
        }

        /**
         * Whether initialize_latest() has already run.
         */
        public function is_initialized(): bool
        {
            return $this->initialized;
        }
    } // class Tangible_DDD_Versions

    // Hook the winner-selection at priority 1 — after all pri-0 registrations.
    if (function_exists('add_action')) {
        add_action('plugins_loaded', [Tangible_DDD_Versions::instance(), 'initialize_latest'], 1, 0);
    }

} // if (!class_exists('Tangible_DDD_Versions'))

// ─── Version-named register function (guarded — safe in N copies) ────────────
// Slug: 0.2.4 → 0_2_4  (dots and hyphens → underscores)
if (!function_exists('tangible_ddd_register_0_2_4')) {

    /**
     * Register this copy (0.2.4) into Tangible_DDD_Versions.
     * Hooked at plugins_loaded priority 0 so all copies register before pri-1 wins.
     */
    function tangible_ddd_register_0_2_4(): void
    {
        // Pass a closure so the callable type check passes even when this register
        // function is invoked before tangible_ddd_initialize_0_2_4() is defined
        // (which can happen in unit-test context where add_action is absent and we
        // self-register immediately at file-include time).
        Tangible_DDD_Versions::instance()->register(
            '0.2.4',
            __DIR__,
            static function (string $path): void {
                tangible_ddd_initialize_0_2_4($path);
            }
        );
    }

    if (function_exists('add_action')) {
        add_action('plugins_loaded', 'tangible_ddd_register_0_2_4', 0, 0);
    } else {
        // Outside WP (unit tests / direct require with no hook system):
        // self-register immediately.  initialize_latest() is NOT called here
        // so that N copies included during tests can each register first;
        // test code can call Tangible_DDD_Versions::instance()->initialize_latest()
        // explicitly, or the bootstrap triggers it below.
        tangible_ddd_register_0_2_4();
    }
}

// ─── Version-named initializer (guarded — the winner calls this) ──────────────
if (!function_exists('tangible_ddd_initialize_0_2_4')) {

    /**
     * Boot this copy of tangible-ddd as the site winner.
     *
     * (a) Prepend an spl_autoloader so THIS copy's classes take precedence over
     *     any psr-4 maps a consumer's composer installed for TangibleDDD\.
     * (b) require_once every procedural ddd-wordpress/*.php file exactly once.
     *
     * @param string $path Absolute path to the winning ddd plugin root.
     */
    function tangible_ddd_initialize_0_2_4(string $path): void
    {
        // (a) Prepend autoloader — winner's classes beat consumer psr-4 maps.
        spl_autoload_register(
            static function (string $class) use ($path): void {
                // TangibleDDD\WordPress\ → ddd-wordpress/
                if (str_starts_with($class, 'TangibleDDD\\WordPress\\')) {
                    $relative = substr($class, strlen('TangibleDDD\\WordPress\\'));
                    $file     = $path . '/ddd-wordpress/' . str_replace('\\', '/', $relative) . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                    return;
                }
                // TangibleDDD\ → ddd-src/
                if (str_starts_with($class, 'TangibleDDD\\')) {
                    $relative = substr($class, strlen('TangibleDDD\\'));
                    $file     = $path . '/ddd-src/' . str_replace('\\', '/', $relative) . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            },
            true,   // throw on error
            true    // prepend → winner beats any later psr-4
        );

        // (b) Procedural files — only files that define functions (not classes).
        //     Class files under ddd-wordpress/ (di/HandlerClassNameInflector.php,
        //     cli/class-ddd-command.php) are resolved on demand by the spl_autoload
        //     registered above; require_once'ing them here would trigger class
        //     loading before their interface dependencies are autoloaded.
        //     cli/register.php executes \WP_CLI::add_command() (not just a function
        //     definition) and guards itself with WP_CLI, so also left to autoload.
        //     Order: db.php first (no deps); others depend on db helpers.
        $procedural = [
            'ddd-src/Domain/Shared/assert.php',
            'ddd-wordpress/db.php',
            'ddd-wordpress/tables.php',
            'ddd-wordpress/migrations.php',
            'ddd-wordpress/hooks.php',
            'ddd-wordpress/audit.php',
            'ddd-wordpress/touches.php',
            'ddd-wordpress/locking.php',
            'ddd-wordpress/secret.php',
            'ddd-wordpress/integration-events.php',
            'ddd-wordpress/infrastructure-events.php',
            // Executes WP_CLI::add_command (self-guarded on WP_CLI). Must be
            // required explicitly — procedural side-effect files are invisible
            // to the class autoloader, so "left to autoload" meant "never
            // loaded" and `wp ddd` only existed where the plugin file itself
            // was the active loader.
            'ddd-wordpress/cli/register.php',
        ];

        foreach ($procedural as $rel) {
            $file = $path . '/' . $rel;
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
}

// ─── Self-consumer boot ───────────────────────────────────────────────────────
// tangible-ddd hosts the DDD stack for its OWN operational commands
// (replay / discard / retry / purge), self-auditing into wp_tangible_ddd_*.
// Runs at plugins_loaded pri 20 (after the winner initialises at pri 1). Guarded,
// once, and defensive: a failure here must never fatal the consumers' site.
if (!function_exists('tangible_ddd_self_consume')) {

    function tangible_ddd_self_consume(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        if (!class_exists('Tangible_DDD_Versions', false)) {
            return;
        }
        $winner = Tangible_DDD_Versions::instance()->winner();
        if ($winner === null) {
            return;
        }

        try {
            require_once $winner['path'] . '/ddd-wordpress/self/index.php';

            // Install/heal the framework's own six tables on admin_init
            // (idempotent, version-gated via the per-prefix schema option).
            if (function_exists('TangibleDDD\\WordPress\\register_migration_hooks')) {
                \TangibleDDD\WordPress\register_migration_hooks(\TangibleDDD\Infra\Config::for_wordpress());
            }
        } catch (\Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[tangible-ddd] self-consume boot failed: ' . $e->getMessage());
            }
        }
    }

    if (function_exists('add_action')) {
        add_action('plugins_loaded', 'tangible_ddd_self_consume', 20, 0);
    }
}

// ─── Immediate init for non-WP contexts (unit tests, CLI scripts) ─────────────
// When add_action does not exist there is no hook system to fire registration +
// initialization on.  The register call above already ran; now initialize so that
// procedural ddd-wordpress/*.php files are loaded before test code runs.
// This mirrors how unit-test bootstraps load Action Scheduler directly.
if (!function_exists('add_action') && !Tangible_DDD_Versions::instance()->is_initialized()) {
    Tangible_DDD_Versions::instance()->initialize_latest();
}

// ─── Late-load safety (mirrors Action Scheduler's theme-usage guard) ──────────
// If plugins_loaded has already fired (theme context, or a late require_once),
// self-register and initialize immediately so consumers don't have to.
if (
    function_exists('did_action')   && did_action('plugins_loaded')
    && function_exists('doing_action') && !doing_action('plugins_loaded')
    && !Tangible_DDD_Versions::instance()->is_initialized()
) {
    tangible_ddd_register_0_2_4();
    Tangible_DDD_Versions::instance()->initialize_latest();
}
