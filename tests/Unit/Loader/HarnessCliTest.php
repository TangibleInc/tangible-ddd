<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{string}> */
    public static function later_waves(): array
    {
        return [
            'core-pdo' => ['core-pdo'],
            'compat' => ['compat'],
        ];
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

    #[DataProvider('later_waves')]
    public function test_subcommands_of_later_waves_exit_2_not_yet_implemented(string $sub): void
    {
        [$code, $out] = self::run_harness($sub);

        $this->assertSame(2, $code, $out);
        $this->assertStringContainsString("{$sub}: not yet implemented", $out);
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
