<?php
/**
 * The winning copy's class autoloader (register 1.5; report B7, F-13).
 *
 * Prepended by the winner's initializer so that every TangibleDDD\ class
 * resolves from the winner's own distribution: `TangibleDDD\WordPress\` from
 * packages/ddd-wp/wordpress/ (plus the two classmapped files under cli/ and
 * self/), every other `TangibleDDD\` name from packages/ddd-core/src/ and
 * then packages/ddd-wp/src/ (one prefix over two directories; each file
 * lives in exactly one, register 1.1).
 *
 * Names in the root compat/ alias map are served as class_alias of their
 * target (register 1.2, R1). When the winner has no file for a name it
 * returns without loading, so the lookup falls through to the next
 * autoloader, as every 0.x loader did. If another copy's Composer loader
 * would serve that name, the fall-through is a mixed load and is reported to
 * Tangible_DDD_Load_Diagnostics. It never throws: class_exists probes run
 * through it (packages/ddd-wp/wordpress/hooks.php).
 *
 * Only the winner's initializer requires this file; the guard covers a
 * direct double include.
 */

declare(strict_types=1);

if (!class_exists('Tangible_DDD_Winner_Autoloader', false)) {

    final class Tangible_DDD_Winner_Autoloader
    {
        private const PREFIX = 'TangibleDDD\\';
        private const WP_PREFIX = 'TangibleDDD\\WordPress\\';

        /** PSR-4 directories for TangibleDDD\ outside TangibleDDD\WordPress\, in lookup order. */
        private const SRC_DIRS = ['packages/ddd-core/src/', 'packages/ddd-wp/src/'];

        private const WP_DIR = 'packages/ddd-wp/wordpress/';

        /**
         * The root manifest's classmap (wordpress/cli, wordpress/self; O15).
         * Mirrored here because a legacy consumer's Composer map would
         * otherwise serve these names from its own copy.
         */
        private const CLASSMAP = [
            'TangibleDDD\\WordPress\\CLI\\DDD_Command' => 'packages/ddd-wp/wordpress/cli/class-ddd-command.php',
            'TangibleDDD\\WordPress\\SelfConsumer\\HandlerClassNameInflector' => 'packages/ddd-wp/wordpress/self/HandlerClassNameInflector.php',
        ];

        private readonly string $root;

        /**
         * @param string                $root    absolute path of the winning distribution
         * @param string                $version the winner's registered version (for messages)
         * @param array<string, string> $aliases legacy FQCN => current FQCN (compat/aliases.php)
         */
        public function __construct(
            string $root,
            private readonly string $version,
            private readonly array $aliases = [],
        ) {
            $this->root = rtrim($root, '/');
        }

        /**
         * Read an alias map file. A missing or malformed file yields no
         * aliases and a diagnostic, never an error.
         *
         * @return array<string, string>
         */
        public static function aliases_from(string $file): array
        {
            if (!is_file($file)) {
                return [];
            }
            $map = include $file;
            if (!is_array($map)) {
                Tangible_DDD_Load_Diagnostics::report('compat-map', sprintf('compat alias map %s does not return an array; ignored.', $file));

                return [];
            }
            $out = [];
            foreach ($map as $legacy => $current) {
                if (is_string($legacy) && is_string($current) && str_starts_with($legacy, self::PREFIX) && $legacy !== $current) {
                    $out[ltrim($legacy, '\\')] = ltrim($current, '\\');
                } else {
                    Tangible_DDD_Load_Diagnostics::report('compat-map', sprintf('compat alias map %s: entry %s ignored.', $file, var_export($legacy, true)));
                }
            }

            return $out;
        }

        /** Prepend to the autoload stack, ahead of every Composer loader. */
        public function register(): void
        {
            spl_autoload_register([$this, 'load'], true, true);
        }

        public function root(): string
        {
            return $this->root;
        }

        public function version(): string
        {
            return $this->version;
        }

        /** The winner's file for $class, or null when the winner has none. */
        public function file_for(string $class): ?string
        {
            $class = ltrim($class, '\\');
            if (!str_starts_with($class, self::PREFIX)) {
                return null;
            }
            if (isset(self::CLASSMAP[$class])) {
                $file = $this->root . '/' . self::CLASSMAP[$class];

                return is_file($file) ? $file : null;
            }
            if (str_starts_with($class, self::WP_PREFIX)) {
                $file = $this->root . '/' . self::WP_DIR . str_replace('\\', '/', substr($class, strlen(self::WP_PREFIX))) . '.php';

                return is_file($file) ? $file : null;
            }
            $relative = str_replace('\\', '/', substr($class, strlen(self::PREFIX))) . '.php';
            foreach (self::SRC_DIRS as $dir) {
                $file = $this->root . '/' . $dir . $relative;
                if (is_file($file)) {
                    return $file;
                }
            }

            return null;
        }

        /** spl_autoload callback. */
        public function load(string $class): void
        {
            $class = ltrim($class, '\\');
            if (!str_starts_with($class, self::PREFIX)) {
                return;
            }

            if (isset($this->aliases[$class])) {
                $target = $this->aliases[$class];
                if (class_exists($target) || interface_exists($target) || trait_exists($target)) {
                    class_alias($target, $class);

                    return;
                }
                Tangible_DDD_Load_Diagnostics::report('compat-map', sprintf(
                    'compat alias %s => %s: the target does not resolve in the winner %s (%s).',
                    $class, $target, $this->version, $this->root
                ));

                return;
            }

            $file = $this->file_for($class);
            if ($file !== null) {
                require_once $file;

                return;
            }

            $this->report_fall_through($class);
        }

        private function report_fall_through(string $class): void
        {
            if (Tangible_DDD_Load_Diagnostics::is_foreign($class)) {
                return;
            }
            $elsewhere = Tangible_DDD_Load_Diagnostics::find_elsewhere($class);
            if ($elsewhere === null || Tangible_DDD_Load_Diagnostics::is_under($elsewhere, $this->root)) {
                return; // nobody serves it (a probe), or it is ours after all
            }
            Tangible_DDD_Load_Diagnostics::report('fall-through', sprintf(
                'fall-through: the winner %s (%s) has no %s; it loads from %s, another copy.',
                $this->version, $this->root, $class, $elsewhere
            ));
        }
    }
}
