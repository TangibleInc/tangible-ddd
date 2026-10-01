<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * The command surface of tests/harness/run.sh (register section 8). Only the
 * dispatch and the pinned inputs are unit-tested here; the wp-integration
 * subcommand needs Docker and is exercised by CI and by hand.
 */
class HarnessCliTest extends TestCase
{
    private static function script(): string
    {
        return dirname(__DIR__, 3) . '/tests/harness/run.sh';
    }

    /** @return array{int, string} exit code, combined output */
    private static function run_harness(string ...$args): array
    {
        $cmd = 'bash ' . escapeshellarg(self::script());
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        exec($cmd . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    public function test_the_script_exists_and_parses(): void
    {
        $this->assertFileExists(self::script());
        exec('bash -n ' . escapeshellarg(self::script()) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_the_compat_subcommand_runs_every_section_of_the_wave_4_gate(): void
    {
        // Register section 8 wave 4: compat is green for every case of 7.2
        // (and 7.3), the CR-PK-5 allowances have expired, the release
        // artifact is clean. The WordPress sections need Docker (CI, by hand).
        $source = (string) file_get_contents(self::script());
        $this->assertMatchesRegularExpression('/^\s*compat\) compat ;;$/m', $source);
        $this->assertStringContainsString('tests/Compat/check-allowances.php', $source);
        $this->assertStringContainsString('tests/Compat/release-artifact.sh', $source);
        $this->assertStringContainsString('tests/Integration/Rollback/phpunit.xml', $source);
        $this->assertStringContainsString('COMPAT_SECTIONS="cs allowances artifact 7.2 7.3"', $source);
        $this->assertStringContainsString('php-cs-fixer check', $source);
        $this->assertStringNotContainsString('compat           compatibility fixtures (not yet implemented)', $source);
    }

    public function test_compat_runs_the_static_sections_without_docker(): void
    {
        $root = dirname(__DIR__, 3);
        if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
            $this->markTestSkipped('not a git checkout (e.g. a git-archive export)');
        }
        exec('DDD_COMPAT_SECTIONS="allowances artifact" bash ' . escapeshellarg(self::script()) . ' compat 2>&1', $lines, $code);
        $out = implode("\n", $lines);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('CR-PK-5 transitional allowances: none remain', $out);
        $this->assertStringContainsString('release artifact of', $out);
        $this->assertStringContainsString('compat: allowances ok, artifact ok', $out);
    }

    public function test_compat_refuses_a_narrowed_or_unknown_run(): void
    {
        foreach (['DDD_LOADER_CASES=load.new-alone' => 'DDD_LOADER_CASES', 'DDD_COMPAT_SECTIONS=bogus' => 'unknown compat section bogus'] as $env => $needle) {
            exec($env . ' bash ' . escapeshellarg(self::script()) . ' compat 2>&1', $lines, $code);
            $out = implode("\n", $lines);
            $this->assertSame(64, $code, $out);
            $this->assertStringContainsString($needle, $out);
            $lines = [];
        }
    }

    public function test_the_core_pdo_subcommand_is_wired(): void
    {
        // Running it needs MySQL 8.0 (an existing server or the pinned
        // image) and the host PHP with pdo_mysql; here only the dispatch
        // and what it runs: the adapter suite, the conformance suite with
        // its per-id gate, and the two-process example.
        $source = (string) file_get_contents(self::script());
        $this->assertMatchesRegularExpression('/^\s*core-pdo\) core_pdo ;;$/m', $source);
        $this->assertStringContainsString('packages/ddd-core/phpunit.pdo.xml', $source);
        $this->assertStringContainsString('packages/ddd-core/tests/Pdo/Conformance/phpunit.xml', $source);
        $this->assertStringContainsString('packages/ddd-core/tests/Pdo/Conformance/bin/check-due.php', $source);
        $this->assertStringContainsString('examples/plain-php-durable/produce.php', $source);
        $this->assertStringContainsString('examples/plain-php-durable/drain.php', $source);
        $this->assertStringNotContainsString('core-pdo         ddd-core Defaults/Pdo suite (not yet implemented)', $source);
    }

    public function test_the_conformance_wp_subcommand_is_wired(): void
    {
        // Running it needs Docker and MySQL 8.0 (CI and by hand); here only
        // the dispatch and the suite + gate it runs.
        $source = (string) file_get_contents(self::script());
        $this->assertMatchesRegularExpression('/^\s*conformance-wp\) conformance_wp ;;$/m', $source);
        $this->assertStringContainsString('tests/Integration/Conformance/phpunit.xml', $source);
        $this->assertStringContainsString('tests/Integration/Conformance/bin/check-due.php', $source);
    }

    public function test_the_loader_subcommand_is_wired_to_the_fixture_driver(): void
    {
        // Running it needs Docker and MySQL 8.0 (CI and by hand); here only
        // the dispatch and the driver's syntax.
        $source = (string) file_get_contents(self::script());
        $this->assertMatchesRegularExpression('/^\s*loader\) loader ;;$/m', $source);
        $this->assertStringContainsString('lib/loader.sh', $source);

        $driver = dirname(__DIR__, 3) . '/tests/harness/lib/loader.sh';
        $this->assertFileExists($driver);
        exec('bash -n ' . escapeshellarg($driver) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_an_unknown_or_missing_subcommand_prints_usage_and_exits_64(): void
    {
        foreach ([[], ['bogus']] as $args) {
            [$code, $out] = self::run_harness(...$args);
            $this->assertSame(64, $code, $out);
            $this->assertStringContainsString('usage:', $out);
            $this->assertStringContainsString('wp-integration', $out);
        }
    }

    public function test_the_harness_inputs_are_pinned(): void
    {
        $lock = dirname(__DIR__, 3) . '/tests/harness/refs.lock';
        $this->assertFileExists($lock);
        // Shell-sourceable KEY=VALUE lines; '#' starts a comment.
        preg_match_all('/^([A-Z_]+)=(\S+)\s*$/m', (string) file_get_contents($lock), $m);
        $vars = array_combine($m[1], $m[2]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $vars['DATASTREAM_REF'] ?? '', 'datastream is pinned by full SHA (F-10).');
        $this->assertMatchesRegularExpression('/^\d+\.\d+(\.\d+)?$/', $vars['WP_VERSION'] ?? '', 'WordPress is pinned to an exact release.');
        $this->assertMatchesRegularExpression('/^mysql:8\.0@sha256:[0-9a-f]{64}$/', $vars['MYSQL_IMAGE'] ?? '', 'MySQL 8.0 is the gating server, pinned by digest.');
        $this->assertMatchesRegularExpression('/^wordpress:cli-php8\.2@sha256:[0-9a-f]{64}$/', $vars['WP_CLI_IMAGE'] ?? '', 'The runner image is PHP 8.2 (the floor), pinned by digest.');
    }
}
