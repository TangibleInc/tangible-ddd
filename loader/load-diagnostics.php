<?php
/**
 * Load-time diagnostics of the winning tangible-ddd copy (register 1.5, 7.2;
 * rulings X1; report B7, B13).
 *
 * Three findings, each logged and kept for the dashboard and the loader
 * fixtures, never thrown (class_exists probes run through the winner's
 * autoloader, and a throw at plugins_loaded:1 would take the site down):
 *
 *  - `fall-through`: the winner has no file for a TangibleDDD\ class and
 *    another copy's autoloader serves it, so the runtime mixes copies (B7).
 *  - `mixed-load`: a TangibleDDD\ class was already loaded from another copy
 *    before the winner initialised (B13, load.preloaded-class).
 *  - `unsupported-version`: a copy older than the compatibility window
 *    (0.2.x-0.5.x) is present (X1, load.v0-2-negative). Such a copy does not
 *    fail on its own; its deleted classes resolve silently from its vendor
 *    tree. Under WP_DEBUG the finding is also raised as an E_USER_WARNING
 *    whose message starts with the error name TANGIBLE_DDD_UNSUPPORTED_VERSION.
 *
 * Only the winner's initializer requires this file, so a single definition is
 * live per request; the guard covers a direct double include.
 */

declare(strict_types=1);

if (!class_exists('Tangible_DDD_Load_Diagnostics', false)) {

    final class Tangible_DDD_Load_Diagnostics
    {
        /** Error name of the unsupported-version finding (rulings X1). */
        public const UNSUPPORTED_VERSION = 'TANGIBLE_DDD_UNSUPPORTED_VERSION';

        /** Oldest in-window copy (register 0.1 X1: the window is >=0.6.2 <0.7). */
        public const WINDOW_FLOOR = '0.6.2';

        /**
         * FQCNs that exist only in 0.2.x-0.3.x copies: deleted in 0.4.0
         * (`50fcd29`), absent from every 0.6.x tag and from this distribution.
         * Seeing one of them served by any autoloader means a pre-window copy.
         */
        public const PRE_WINDOW_FQCNS = [
            'TangibleDDD\\Application\\Correlation\\CorrelationContext',
            'TangibleDDD\\Application\\Logging\\CommandAuditMiddleware',
            'TangibleDDD\\Application\\EventHandlers\\AsyncWordPressActionHandler',
            'TangibleDDD\\Application\\Events\\TransportEnvelope',
        ];

        /**
         * TangibleDDD\ sub-namespaces owned by other packages (ddd-symfony,
         * ddd-conformance) or by test suites: never part of this distribution,
         * so never a mixture.
         */
        public const FOREIGN_PREFIXES = [
            'TangibleDDD\\Symfony\\',
            'TangibleDDD\\Conformance\\',
            'TangibleDDD\\Tests\\',
        ];

        /** @var list<array{code: string, message: string}> */
        private static array $findings = [];

        /** @var (\Closure(string): void)|null */
        private static ?\Closure $log = null;

        /** Record a finding and log it once. */
        public static function report(string $code, string $message): void
        {
            foreach (self::$findings as $f) {
                if ($f['code'] === $code && $f['message'] === $message) {
                    return;
                }
            }
            self::$findings[] = ['code' => $code, 'message' => $message];
            $line = '[tangible-ddd] ' . $message;
            if (self::$log !== null) {
                (self::$log)($line);
            } elseif (function_exists('error_log')) {
                error_log($line);
            }
        }

        /**
         * Everything reported this request, optionally for one code.
         *
         * @return list<array{code: string, message: string}>
         */
        public static function findings(?string $code = null): array
        {
            if ($code === null) {
                return self::$findings;
            }

            return array_values(array_filter(self::$findings, static fn(array $f): bool => $f['code'] === $code));
        }

        /**
         * Replace the log sink (null restores error_log). For tests and hosts
         * that route diagnostics elsewhere.
         *
         * @param (\Closure(string): void)|null $log
         */
        public static function use_log(?\Closure $log): void
        {
            self::$log = $log;
        }

        /** Forget all findings. Test seam; a request never needs it. */
        public static function reset(): void
        {
            self::$findings = [];
        }

        public static function is_foreign(string $class): bool
        {
            foreach (self::FOREIGN_PREFIXES as $prefix) {
                if (str_starts_with($class, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        /** Whether $file lies inside the distribution rooted at $root. */
        public static function is_under(string $file, string $root): bool
        {
            $real_file = realpath($file) ?: $file;
            $real_root = rtrim(realpath($root) ?: $root, '/') . '/';

            return str_starts_with($real_file, $real_root);
        }

        /**
         * The file another autoloader would load $class from, without loading
         * it. Asks every registered Composer ClassLoader (`findFile`); other
         * autoloaders cannot be asked and are skipped.
         *
         * @param list<callable>|null $loaders defaults to spl_autoload_functions()
         */
        public static function find_elsewhere(string $class, ?array $loaders = null): ?string
        {
            foreach ($loaders ?? spl_autoload_functions() as $loader) {
                if (!is_array($loader) || !is_object($loader[0] ?? null) || !method_exists($loader[0], 'findFile')) {
                    continue;
                }
                $file = $loader[0]->findFile($class);
                if (is_string($file) && $file !== '') {
                    return $file;
                }
            }

            return null;
        }

        /**
         * Copies below the compatibility window: registered versions under
         * WINDOW_FLOOR, plus any pre-window FQCN that is loaded or that some
         * autoloader would serve.
         *
         * @param array<string, string> $registered version => path (Tangible_DDD_Versions::all_registered())
         * @param list<callable>|null   $loaders    defaults to spl_autoload_functions()
         * @return list<string> human-readable evidence, empty when none
         */
        public static function pre_window_evidence(array $registered, ?array $loaders = null): array
        {
            $evidence = [];
            foreach ($registered as $version => $path) {
                if (version_compare((string) $version, self::WINDOW_FLOOR, '<')) {
                    $evidence[] = sprintf('copy %s registered from %s', $version, $path);
                }
            }
            foreach (self::PRE_WINDOW_FQCNS as $fqcn) {
                if (class_exists($fqcn, false) || interface_exists($fqcn, false) || trait_exists($fqcn, false)) {
                    $file = (new \ReflectionClass($fqcn))->getFileName();
                    $evidence[] = sprintf('%s is loaded from %s', $fqcn, $file ?: '(unknown)');
                    continue;
                }
                $file = self::find_elsewhere($fqcn, $loaders);
                if ($file !== null) {
                    $evidence[] = sprintf('%s resolves to %s', $fqcn, $file);
                }
            }

            return $evidence;
        }

        /**
         * TangibleDDD\ classes, interfaces and traits already declared from
         * outside $root (foreign packages excepted).
         *
         * @param list<string>|null $declared defaults to everything declared
         * @return array<string, string> class => file
         */
        public static function declared_elsewhere(string $root, ?array $declared = null): array
        {
            $declared ??= array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits());
            $out = [];
            foreach ($declared as $class) {
                if (!str_starts_with($class, 'TangibleDDD\\') || self::is_foreign($class)) {
                    continue;
                }
                $file = (new \ReflectionClass($class))->getFileName();
                if (is_string($file) && !self::is_under($file, $root)) {
                    $out[$class] = $file;
                }
            }
            ksort($out);

            return $out;
        }

        /**
         * Run the boot-time probes for the winner at $root. Called by the
         * winner's initializer after its autoloader is registered.
         *
         * @param array<string, string> $registered version => path
         * @param bool|null             $debug      defaults to WP_DEBUG
         * @param list<string>|null     $declared   defaults to everything declared
         */
        public static function at_winner_boot(string $version, string $root, array $registered, ?bool $debug = null, ?array $declared = null): void
        {
            foreach (self::declared_elsewhere($root, $declared) as $class => $file) {
                self::report('mixed-load', sprintf(
                    'mixed load: %s was loaded from %s before the winner %s (%s) initialised; it stays in use for this request.',
                    $class, $file, $version, $root
                ));
            }

            $evidence = self::pre_window_evidence($registered);
            if ($evidence === []) {
                return;
            }
            $message = sprintf(
                '%s: a tangible-ddd copy older than %s is installed alongside the winner %s; 0.2.x-0.5.x copies are unsupported (register 7.4) and their removed classes load silently from their own vendor tree. Evidence: %s.',
                self::UNSUPPORTED_VERSION, self::WINDOW_FLOOR, $version, implode('; ', $evidence)
            );
            self::report('unsupported-version', $message);

            $debug ??= defined('WP_DEBUG') && (bool) constant('WP_DEBUG');
            if ($debug) {
                trigger_error($message, E_USER_WARNING);
            }
        }
    }
}
