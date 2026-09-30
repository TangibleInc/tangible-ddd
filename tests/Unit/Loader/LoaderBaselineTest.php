<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * The recorded `load.legacy-first` baseline (register 7.2, wave 1) is the
 * reference the wave-2 loader fixtures compare against. It is produced by
 * tests/Loader/record-legacy-first.sh on a real WordPress + MySQL 8.0; this
 * test only guards that the recording is present and complete, not what it
 * says (today's behaviour is not the pass condition).
 */
class LoaderBaselineTest extends TestCase
{
    public function test_the_legacy_first_baseline_is_recorded_for_each_legacy_copy(): void
    {
        $path = dirname(__DIR__, 3) . '/tests/Loader/baselines/load.legacy-first.json';
        $this->assertFileExists($path);
        $baseline = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('load.legacy-first', $baseline['case']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $baseline['recorded_against']['current_sha'] ?? '');
        $this->assertSame(['L-0.6.2 then current', 'L-0.6.5 then current'], array_keys($baseline['cases']));

        foreach ($baseline['cases'] as $name => $case) {
            foreach (['active_plugins', 'loader_files_included', 'registered', 'winner', 'unmet_minimums', 'class_origin', 'function_origin', 'self_consumer', 'trace', 'php_diagnostics'] as $key) {
                $this->assertArrayHasKey($key, $case, "{$name} records {$key}");
            }
            $this->assertCount(2, $case['active_plugins'], "{$name}: legacy plugin then today's plugin");
            $this->assertStringStartsWith('fx-legacy-', $case['active_plugins'][0]);
        }
    }
}
