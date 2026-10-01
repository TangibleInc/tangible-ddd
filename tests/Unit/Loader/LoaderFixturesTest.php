<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * The host-side halves of `tests/harness/run.sh loader`: the case list
 * (tests/Loader/cases.php) covers register 7.2 for wave 2, and the judge
 * (tests/Loader/assert-case.php) fails on what each pass condition forbids.
 * The WordPress half runs in Docker (CI and by hand).
 */
final class LoaderFixturesTest extends TestCase
{
    private static function loader_dir(): string
    {
        return dirname(__DIR__, 3) . '/tests/Loader';
    }

    /** @return list<array{0: string, 1: list<string>, 2: string, 3: string, 4: array<string, mixed>}> */
    private static function cases(): array
    {
        $env = 'DDD_N_VERSION=0.7.0 DDD_LEGACY=' . escapeshellarg('legacy-0_6_2=0.6.2 legacy-0_6_5=0.6.5')
            . ' DDD_NEGATIVE=legacy-0_2_5=0.2.5 DDD_PRELOAD=preload-0_6_5=0.6.5';
        exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::loader_dir() . '/cases.php') . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));

        return array_map(static function (string $line): array {
            $f = explode("\t", $line);
            self::assertCount(5, $f, $line);

            return [$f[0], json_decode($f[1], true, 512, JSON_THROW_ON_ERROR), $f[2], $f[3], json_decode($f[4], true, 512, JSON_THROW_ON_ERROR)];
        }, $lines);
    }

    public function test_every_wave_2_case_of_register_7_2_is_listed(): void
    {
        $ids = array_column(self::cases(), 0);
        $kinds = array_values(array_unique(array_map(static fn(string $id): string => (string) preg_replace('/\[.*$/', '', $id), $ids)));
        sort($kinds);

        // 7.2 minus load.jetpack-mixed (wave 4) and load.compiled-containers
        // (needs the shipped zips; the driver reports it SKIP, wave 4 resolves).
        $this->assertSame([
            'load.late', 'load.legacy-first', 'load.min-unmet', 'load.new-alone', 'load.new-first',
            'load.new-twice', 'load.plugin-active', 'load.preloaded-class', 'load.v0-2-negative',
        ], $kinds);

        foreach (['legacy-0_6_2', 'legacy-0_6_5'] as $legacy) {
            $this->assertContains("load.legacy-first[{$legacy}]", $ids);
            $this->assertContains("load.new-first[{$legacy}]", $ids);
        }
        foreach (['legacy-first,debug', 'legacy-first,no-debug', 'new-first,debug', 'new-first,no-debug'] as $variant) {
            $this->assertContains("load.v0-2-negative[{$variant}]", $ids);
        }
    }

    public function test_both_orders_are_really_both_orders(): void
    {
        $by_id = array_column(self::cases(), 1, 0);

        $this->assertSame(['fx-legacy-0_6_5/fx-legacy-0_6_5.php', 'fx-new/fx-new.php'], $by_id['load.legacy-first[legacy-0_6_5]']);
        $this->assertSame(['fx-new/fx-new.php', 'fx-legacy-0_6_5/fx-legacy-0_6_5.php'], $by_id['load.new-first[legacy-0_6_5]']);
    }

    public function test_the_v0_2_case_expects_a_raise_only_under_wp_debug(): void
    {
        foreach (self::cases() as [$id, , $debug, , $spec]) {
            if (!str_starts_with($id, 'load.v0-2-negative')) {
                continue;
            }
            $this->assertSame(['unsupported-version'], $spec['findings']);
            $this->assertContains('TANGIBLE_DDD_UNSUPPORTED_VERSION', $spec['log_contains']);
            $this->assertSame($debug === '1' ? ['TANGIBLE_DDD_UNSUPPORTED_VERSION'] : [], $spec['raised'], $id);
        }
    }

    /** @return array<string, mixed> a probe result in which N won cleanly over a legacy copy */
    private static function clean_probe(): array
    {
        $new = 'new';
        $classes = [
            'Tangible_DDD_Versions' => 'legacy-0_6_5',
            'TangibleDDD\\Application\\Process\\ProcessRunner' => $new,
            'TangibleDDD\\WordPress\\Admin\\Dashboard\\AdminPage' => $new,
        ];

        return [
            'active_plugins' => ['fx-legacy-0_6_5/fx-legacy-0_6_5.php', 'fx-new/fx-new.php'],
            'framework_version' => '0.7.0',
            'registered' => ['0.6.5' => 'legacy-0_6_5', '0.7.0' => $new],
            'winner' => ['version' => '0.7.0', 'copy' => $new],
            'initialized' => true,
            'unmet_minimums' => [],
            'class_origin' => $classes,
            'function_origin' => [
                'tangible_ddd_self_consume' => 'legacy-0_6_5',
                'TangibleDDD\\WordPress\\boot' => $new,
                'TangibleDDD\\WordPress\\SelfConsumer\\di' => $new,
            ],
            'self_consumer' => 'built:Symfony\\Component\\DependencyInjection\\ContainerBuilder',
            'wp_ddd_command' => true,
            'copy_non_loader_files' => ['legacy-0_6_5' => [], $new => ['packages/ddd-wp/wordpress/hooks.php']],
            'round_trip' => ['result' => 'handled:alice', 'heard' => ['alice'], 'error' => null],
            'diagnostics' => [],
            'error_log' => [],
            'trace' => array_map(static fn($p): array => ['event' => 'plugins_loaded', 'priority' => $p], [0, 1, 2, 20, 30, 'last']),
            'php_diagnostics' => [['level' => E_DEPRECATED, 'message' => 'legacy deprecation', 'file' => 'copy:legacy-0_6_5:x.php']],
        ];
    }

    /** @return array{int, string} */
    private static function judge(array $probe, array $spec): array
    {
        $file = tempnam(sys_get_temp_dir(), 'ddd-probe');
        file_put_contents($file, "noise before the JSON\n" . json_encode($probe));
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::loader_dir() . '/assert-case.php')
            . ' load.test ' . escapeshellarg($file) . ' ' . escapeshellarg(json_encode($spec)) . ' 2>&1',
            $out,
            $code
        );
        unlink($file);

        return [$code, implode("\n", $out)];
    }

    private const SPEC = [
        'winner_version' => '0.7.0',
        'winner_copy' => 'new',
        'registered' => ['0.6.5' => 'legacy-0_6_5', '0.7.0' => 'new'],
        'losers' => ['legacy-0_6_5'],
    ];

    public function test_the_judge_passes_a_clean_win(): void
    {
        [$code, $out] = self::judge(self::clean_probe(), self::SPEC);

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('PASS load.test', $out);
    }

    /** @return array<string, array{\Closure(array): array, string}> */
    public static function broken(): array
    {
        return [
            'legacy winner' => [static fn(array $p): array => ['winner' => ['version' => '0.6.5', 'copy' => 'legacy-0_6_5']] + $p, 'winner 0.6.5'],
            'loser ran code' => [static function (array $p): array {
                $p['copy_non_loader_files']['legacy-0_6_5'] = ['ddd-wordpress/hooks.php'];

                return $p;
            }, 'losing copy legacy-0_6_5 ran 1 files'],
            'class from another copy' => [static function (array $p): array {
                $p['class_origin']['TangibleDDD\\Application\\Process\\ProcessRunner'] = 'legacy-0_6_5';

                return $p;
            }, 'ProcessRunner loaded from legacy-0_6_5'],
            'fatal before plugins_loaded:30' => [static function (array $p): array {
                $p['trace'] = array_slice($p['trace'], 0, 3);

                return $p;
            }, 'plugins_loaded:30 never ran'],
            'round trip broken' => [static function (array $p): array {
                $p['round_trip']['heard'] = ['alice', 'alice'];

                return $p;
            }, 'round trip'],
            'self consumer missing' => [static fn(array $p): array => ['self_consumer' => 'absent'] + $p, 'self-consumer absent'],
            'unexpected warning' => [static function (array $p): array {
                $p['php_diagnostics'][] = ['level' => E_WARNING, 'message' => 'boom', 'file' => 'x'];

                return $p;
            }, 'PHP diagnostic level 2: boom'],
            'silent diagnostics' => [static function (array $p): array {
                $p['diagnostics'] = [['code' => 'fall-through', 'message' => 'm']];

                return $p;
            }, 'diagnostics'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broken')]
    public function test_the_judge_fails_what_the_pass_condition_forbids(\Closure $break, string $reason): void
    {
        [$code, $out] = self::judge($break(self::clean_probe()), self::SPEC);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('FAIL load.test', $out);
        $this->assertStringContainsString($reason, $out);
    }

    public function test_the_judge_fails_when_the_probe_printed_nothing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ddd-probe');
        file_put_contents($file, 'PHP Fatal error: something');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::loader_dir() . '/assert-case.php') . ' load.test ' . escapeshellarg($file) . " '{}' 2>&1", $out, $code);
        unlink($file);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('printed no JSON', implode("\n", $out));
    }
}
