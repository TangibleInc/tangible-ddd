<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The committed load.compiled-containers fixtures (register 7.1
 * L-0.6.5-compiled, 7.2; report D F5): copies of the ddd service factories
 * of the compiled containers shipped in tangible-lms-0.12.0,
 * tangible-quiz-0.7.0 and tangible-certificates-0.3.1, made by
 * tests/Loader/bin/extract-compiled-container.php.
 *
 * Statically, here: every frozen `new \TangibleDDD\X(...)` and
 * `\TangibleDDD\X::m(...)` call names a class this distribution has, with an
 * argument count its signature accepts (rule R2). The WordPress half
 * (`run.sh loader`, load.compiled-containers) resolves every service.
 */
final class CompiledContainerFixturesTest extends TestCase
{
    private const FIXTURES = ['lms-0_12_0', 'quiz-0_7_0', 'certificates-0_3_1'];

    private static function dir(string $label): string
    {
        return dirname(__DIR__, 3) . '/tests/Loader/fixtures/compiled/' . $label;
    }

    /** @return array<string, array{string}> */
    public static function fixtures(): array
    {
        return array_combine(self::FIXTURES, array_map(static fn(string $l): array => [$l], self::FIXTURES));
    }

    /** @return array<string, mixed> */
    private static function manifest(string $label): array
    {
        $path = self::dir($label) . '/manifest.json';
        self::assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('fixtures')]
    public function test_the_fixture_names_its_shipped_source_and_lints(string $label): void
    {
        $m = self::manifest($label);
        $zip = ['lms-0_12_0' => 'tangible-lms-0.12.0.zip', 'quiz-0_7_0' => 'tangible-quiz-0.7.0.zip', 'certificates-0_3_1' => 'tangible-certificates-0.3.1.zip'][$label];

        $this->assertSame($label, $m['label']);
        $this->assertSame($zip . ':var/container/CompiledContainer.php', $m['source']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $m['source_sha256']);
        foreach (['CompiledContainer.php', 'Consumer.php'] as $file) {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(self::dir($label) . '/' . $file) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
        $this->assertStringContainsString('sha256 ' . $m['source_sha256'], (string) file_get_contents(self::dir($label) . '/CompiledContainer.php'));
    }

    #[DataProvider('fixtures')]
    public function test_the_fixture_keeps_the_ddd_services_report_d_counts(string $label): void
    {
        $m = self::manifest($label);

        // Report D section 4: 16 distinct ddd FQCNs in LMS, 15 in quiz, 16 in
        // certificates (the extractor also counts the IDDDConfig alias and
        // OutboxConfig::from_options, so it finds at least that many).
        $this->assertGreaterThanOrEqual(['lms-0_12_0' => 16, 'quiz-0_7_0' => 15, 'certificates-0_3_1' => 16][$label], count($m['ddd_fqcns']));
        foreach ([
            'TangibleDDD\\Application\\Persistence\\TransactionMiddleware',
            'TangibleDDD\\Application\\Process\\ProcessRunner',
            'TangibleDDD\\Application\\Correlation\\CorrelationMiddleware',
            'TangibleDDD\\Infra\\Services\\OutboxProcessor',
            'TangibleDDD\\Infra\\Services\\ActionSchedulerOutboxPublisher',
            'TangibleDDD\\Infra\\Services\\WordPressEventDispatcher',
            'TangibleDDD\\Application\\Outbox\\OutboxConfig',
        ] as $fqcn) {
            $this->assertContains($fqcn, $m['ddd_fqcns'], "{$label} constructs {$fqcn}");
        }
        foreach (array_keys($m['services']) as $id) {
            $this->assertMatchesRegularExpression('/^(TangibleDDD\\\\|League\\\\Tactician\\\\CommandBus$|tactician\.query_bus$)/', $id);
        }
        $this->assertArrayHasKey('TangibleDDD\\Application\\Process\\ProcessRunner', $m['services']);
    }

    /** @return list<array{string, string, int, int}> class, method, argc, line */
    private static function calls(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $calls = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_NAME_FULLY_QUALIFIED || !str_starts_with($t[1], '\\TangibleDDD\\')) {
                continue;
            }
            $prev = $i - 1;
            while ($prev >= 0 && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
                $prev--;
            }
            $j = $i + 1;
            $method = '__construct';
            if (is_array($tokens[$prev]) && $tokens[$prev][0] === T_NEW) {
                // new \X(
            } elseif (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_DOUBLE_COLON) {
                $method = $tokens[$j + 1][1];
                $j += 2;
            } else {
                continue;
            }
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }
            // Count top-level arguments.
            $depth = 0;
            $argc = 0;
            $seen = false;
            for ($k = $j; $k < $n; $k++) {
                $tk = $tokens[$k];
                $s = is_array($tk) ? $tk[1] : $tk;
                if ($s === '(' || $s === '[' || $s === '{') {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($s === ')' || $s === ']' || $s === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                if ($depth === 1 && $s === ',') {
                    $argc++;
                } elseif (!is_array($tk) || $tk[0] !== T_WHITESPACE) {
                    $seen = true;
                }
            }
            $calls[] = [ltrim($t[1], '\\'), $method, $seen ? $argc + 1 : 0, $t[2]];
        }

        return $calls;
    }

    #[DataProvider('fixtures')]
    public function test_every_frozen_ddd_call_fits_this_distributions_signature(string $label): void
    {
        $calls = self::calls(self::dir($label) . '/CompiledContainer.php');
        $this->assertGreaterThanOrEqual(14, count($calls), 'the fixture carries the frozen ddd calls');

        foreach ($calls as [$class, $method, $argc, $line]) {
            $this->assertTrue(class_exists($class) || interface_exists($class), "{$label}:{$line} {$class} exists in this distribution");
            $ref = new \ReflectionClass($class);
            $this->assertFalse($ref->isAbstract() && $method === '__construct', "{$label}:{$line} {$class} is instantiable");
            if ($method === '__construct' && $ref->getConstructor() === null) {
                $this->assertSame(0, $argc, "{$label}:{$line} new {$class}() without a constructor takes no arguments");
                continue;
            }
            $fn = $method === '__construct' ? $ref->getConstructor() : $ref->getMethod($method);
            $this->assertTrue($fn->isPublic(), "{$label}:{$line} {$class}::{$method} is public");
            $this->assertGreaterThanOrEqual($fn->getNumberOfRequiredParameters(), $argc, "{$label}:{$line} {$class}::{$method} gets its required arguments");
            $this->assertTrue($fn->isVariadic() || $argc <= $fn->getNumberOfParameters(), "{$label}:{$line} {$class}::{$method} accepts {$argc} arguments");
        }
    }
}
