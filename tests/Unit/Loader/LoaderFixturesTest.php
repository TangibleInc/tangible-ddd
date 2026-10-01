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
            . ' DDD_NEGATIVE=legacy-0_2_5=0.2.5 DDD_PRELOAD=preload-0_6_5=0.6.5'
            . ' DDD_N_NEXT_VERSION=0.7.1 DDD_JETPACK_LEGACY=jp-legacy-0_6_5=0.6.5'
            . ' DDD_COMPILED=' . escapeshellarg('cc-lms-0_12_0=jetpack cc-quiz-0_7_0=jetpack cc-certificates-0_3_1=composer')
            . ' DDD_COMPILED_VERSION=0.6.5';
        exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::loader_dir() . '/cases.php') . ' 2>&1', $lines, $code);
        self::assertSame(0, $code, implode("\n", $lines));

        return array_map(static function (string $line): array {
            $f = explode("\t", $line);
            self::assertCount(5, $f, $line);

            return [$f[0], json_decode($f[1], true, 512, JSON_THROW_ON_ERROR), $f[2], $f[3], json_decode($f[4], true, 512, JSON_THROW_ON_ERROR)];
        }, $lines);
    }

    public function test_every_case_of_register_7_2_is_listed(): void
    {
        $ids = array_column(self::cases(), 0);
        $kinds = array_values(array_unique(array_map(static fn(string $id): string => (string) preg_replace('/\[.*$/', '', $id), $ids)));
        sort($kinds);

        // All of 7.2 (wave 4 adds load.compiled-containers and load.jetpack-mixed).
        $this->assertSame([
            'load.compiled-containers', 'load.jetpack-mixed', 'load.late', 'load.legacy-first', 'load.min-unmet',
            'load.new-alone', 'load.new-first', 'load.new-twice', 'load.plugin-active', 'load.preloaded-class',
            'load.v0-2-negative',
        ], $kinds);

        foreach (['legacy-0_6_2', 'legacy-0_6_5'] as $legacy) {
            $this->assertContains("load.legacy-first[{$legacy}]", $ids);
            $this->assertContains("load.new-first[{$legacy}]", $ids);
        }
        foreach (['legacy-first,debug', 'legacy-first,no-debug', 'new-first,debug', 'new-first,no-debug'] as $variant) {
            $this->assertContains("load.v0-2-negative[{$variant}]", $ids);
        }
    }

    public function test_jetpack_mixed_pairs_a_jetpack_plugin_with_a_plain_composer_plugin_of_another_build(): void
    {
        // Register 7.2 load.jetpack-mixed / report D F13: LMS (Jetpack
        // Autoloader) + cred (plain Composer), different builds; every
        // TangibleDDD\ class from one distribution path.
        $by_id = array_column(self::cases(), null, 0);
        $expect = [
            'load.jetpack-mixed[jetpack-older,jetpack-first]' => [['fx-jp-new/fx-jp-new.php', 'fx-next/fx-next.php'], '0.7.1', 'next'],
            'load.jetpack-mixed[jetpack-older,plain-first]' => [['fx-next/fx-next.php', 'fx-jp-new/fx-jp-new.php'], '0.7.1', 'next'],
            'load.jetpack-mixed[jetpack-newer,jetpack-first]' => [['fx-jp-next/fx-jp-next.php', 'fx-new/fx-new.php'], '0.7.1', 'jp-next'],
            'load.jetpack-mixed[jetpack-newer,plain-first]' => [['fx-new/fx-new.php', 'fx-jp-next/fx-jp-next.php'], '0.7.1', 'jp-next'],
            'load.jetpack-mixed[jp-legacy-0_6_5,jetpack-first]' => [['fx-jp-legacy-0_6_5/fx-jp-legacy-0_6_5.php', 'fx-new/fx-new.php'], '0.7.0', 'new'],
            'load.jetpack-mixed[jp-legacy-0_6_5,plain-first]' => [['fx-new/fx-new.php', 'fx-jp-legacy-0_6_5/fx-jp-legacy-0_6_5.php'], '0.7.0', 'new'],
        ];
        foreach ($expect as $id => [$plugins, $version, $copy]) {
            $this->assertArrayHasKey($id, $by_id);
            [, $active, , , $spec] = $by_id[$id];
            $this->assertSame($plugins, $active, $id);
            $this->assertSame($version, $spec['winner_version'], $id);
            $this->assertSame($copy, $spec['winner_copy'], $id);
            $this->assertTrue($spec['single_origin'], "{$id}: every TangibleDDD\\ class from the winner");
            $this->assertTrue($spec['jetpack'], "{$id}: the Jetpack Autoloader is really in play");
        }
    }

    public function test_compiled_containers_resolve_all_three_shipped_fixtures_under_n_in_both_orders(): void
    {
        $by_id = array_column(self::cases(), null, 0);
        $cc = ['fx-cc-lms-0_12_0/fx-cc-lms-0_12_0.php', 'fx-cc-quiz-0_7_0/fx-cc-quiz-0_7_0.php', 'fx-cc-certificates-0_3_1/fx-cc-certificates-0_3_1.php'];

        foreach (['legacy-first' => [...$cc, 'fx-new/fx-new.php'], 'new-first' => ['fx-new/fx-new.php', ...$cc]] as $order => $plugins) {
            $id = "load.compiled-containers[{$order}]";
            $this->assertArrayHasKey($id, $by_id);
            [, $active, , , $spec] = $by_id[$id];
            $this->assertSame($plugins, $active);
            $this->assertSame('new', $spec['winner_copy']);
            $this->assertSame(['lms-0_12_0', 'quiz-0_7_0', 'certificates-0_3_1'], $spec['compiled']);
            $this->assertSame(['0.6.5', '0.7.0'], $spec['registered_versions']);
            $this->assertSame(['cc-lms-0_12_0', 'cc-quiz-0_7_0', 'cc-certificates-0_3_1'], $spec['losers']);
            $this->assertTrue($spec['single_origin']);
            $this->assertTrue($spec['jetpack'], 'LMS and quiz load through the Jetpack Autoloader');
        }
    }

    public function test_the_case_list_reads_the_fixture_kinds_from_the_environment(): void
    {
        // The driver builds what cases.php is told: the compiled fixtures
        // as Jetpack or plain Composer plugins (LMS and quiz ship the Jetpack
        // Autoloader, certificates does not).
        exec(
            'DDD_N_VERSION=0.7.0 DDD_COMPILED=bogus=jetpack ' . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(self::loader_dir() . '/cases.php') . ' 2>&1',
            $out,
            $code
        );
        $this->assertSame(1, $code, implode("\n", $out));
        $this->assertStringContainsString('cc- prefix', implode("\n", $out));
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

    /** @return array<string, mixed> clean_probe() plus a resolved compiled container and the class census */
    private static function compiled_probe(): array
    {
        return [
            'compiled' => [
                'lms-0_12_0' => [
                    'container' => 'FxCompiled\\Lms\\CompiledContainer',
                    'expected' => 2,
                    'resolved' => [
                        'TangibleDDD\\Application\\Process\\ProcessRunner' => ['class' => 'TangibleDDD\\Application\\Process\\ProcessRunner', 'origin' => 'new'],
                        'League\\Tactician\\CommandBus' => ['class' => 'League\\Tactician\\CommandBus', 'origin' => 'plugin:cc-lms-0_12_0'],
                    ],
                    'errors' => [],
                    'mismatches' => [],
                ],
            ],
            'ddd_class_copies' => ['new' => 120],
            'ddd_class_samples' => ['new' => ['TangibleDDD\\Application\\Process\\ProcessRunner']],
        ] + self::clean_probe();
    }

    private const COMPILED_SPEC = self::SPEC + ['compiled' => ['lms-0_12_0'], 'single_origin' => true];

    public function test_the_judge_passes_resolved_compiled_containers_from_one_origin(): void
    {
        [$code, $out] = self::judge(self::compiled_probe(), self::COMPILED_SPEC);

        $this->assertSame(0, $code, $out);
    }

    /** @return array<string, array{\Closure(array): array, string}> */
    public static function broken_compiled(): array
    {
        return [
            'container missing' => [static function (array $p): array {
                unset($p['compiled']['lms-0_12_0']);

                return $p;
            }, 'compiled container lms-0_12_0 was not registered'],
            'service throws' => [static function (array $p): array {
                $p['compiled']['lms-0_12_0']['errors']['TangibleDDD\\Infra\\Services\\OutboxProcessor'] = 'ArgumentCountError: Too few arguments';

                return $p;
            }, 'lms-0_12_0: TangibleDDD\\Infra\\Services\\OutboxProcessor: ArgumentCountError'],
            'service unresolved' => [static function (array $p): array {
                $p['compiled']['lms-0_12_0']['expected'] = 3;

                return $p;
            }, 'lms-0_12_0: resolved 2 of 3 services'],
            'wrong class' => [static function (array $p): array {
                $p['compiled']['lms-0_12_0']['mismatches'] = ['TangibleDDD\\Infra\\IOutboxRepository: got X, expected Y'];

                return $p;
            }, 'lms-0_12_0: TangibleDDD\\Infra\\IOutboxRepository: got X'],
            'ddd class from a loser' => [static function (array $p): array {
                $p['compiled']['lms-0_12_0']['resolved']['TangibleDDD\\Application\\Process\\ProcessRunner']['origin'] = 'cc-lms-0_12_0';

                return $p;
            }, 'lms-0_12_0: TangibleDDD\\Application\\Process\\ProcessRunner resolved from cc-lms-0_12_0'],
            'mixed census' => [static function (array $p): array {
                $p['ddd_class_copies']['legacy-0_6_5'] = 3;
                $p['ddd_class_samples']['legacy-0_6_5'] = ['TangibleDDD\\Infra\\IDDDConfig'];

                return $p;
            }, '3 TangibleDDD classes from legacy-0_6_5, not the winner new (TangibleDDD\\Infra\\IDDDConfig)'],
            'no census' => [static function (array $p): array {
                unset($p['ddd_class_copies']);

                return $p;
            }, 'no TangibleDDD class census'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('broken_compiled')]
    public function test_the_judge_fails_unresolved_or_mixed_compiled_containers(\Closure $break, string $reason): void
    {
        [$code, $out] = self::judge($break(self::compiled_probe()), self::COMPILED_SPEC);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString($reason, $out);
    }

    public function test_the_judge_requires_a_live_jetpack_autoloader_where_the_case_has_one(): void
    {
        $spec = self::SPEC + ['jetpack' => true];
        $probe = self::clean_probe();

        $probe['autoloaders'] = ['Tangible_DDD_Winner_Autoloader::load', 'Composer\\Autoload\\ClassLoader::loadClass'];
        [$code, $out] = self::judge($probe, $spec);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('no Jetpack autoloader is registered', $out);

        $probe['autoloaders'][] = 'Automattic\\Jetpack\\Autoloader\\jp380ae\\al5_0_23\\PHP_Autoloader::load_class';
        [$code, $out] = self::judge($probe, $spec);
        $this->assertSame(0, $code, $out);
    }

    public function test_the_judge_checks_registered_versions_without_naming_copies(): void
    {
        $spec = ['winner_version' => '0.7.0', 'winner_copy' => 'new', 'registered_versions' => ['0.6.5', '0.7.0']];
        [$code, $out] = self::judge(self::clean_probe(), $spec);
        $this->assertSame(0, $code, $out);

        [$code, $out] = self::judge(self::clean_probe(), ['registered_versions' => ['0.7.0']] + $spec);
        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('registered versions ["0.6.5","0.7.0"], expected ["0.7.0"]', $out);
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
