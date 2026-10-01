<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The CI gate for the extraction branches (register section 8 wave 1,
 * report F-8): the existing phpunit.yml never runs on extraction/**.
 */
class ExtractionWorkflowTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function workflow(): array
    {
        $path = dirname(__DIR__, 3) . '/.github/workflows/extraction.yml';
        self::assertFileExists($path);

        return Yaml::parseFile($path);
    }

    /** @param array<string, mixed> $job */
    private static function run_lines(array $job): string
    {
        return implode("\n", array_map(static fn(array $s): string => (string) ($s['run'] ?? ''), $job['steps'] ?? []));
    }

    public function test_it_runs_on_pushes_to_extraction_branches(): void
    {
        $wf = self::workflow();
        // YAML 1.1 reads a bare `on` key as boolean true.
        $on = $wf['on'] ?? $wf[true] ?? [];

        $this->assertContains('extraction/**', $on['push']['branches'] ?? []);
    }

    public function test_wp_unit_runs_the_root_suite_on_the_floor_and_latest_php(): void
    {
        $job = self::workflow()['jobs']['wp-unit'] ?? [];

        $this->assertSame(['8.2', '8.4'], $job['strategy']['matrix']['php'] ?? null);
        $this->assertStringContainsString('vendor/bin/phpunit', self::run_lines($job));
    }

    public function test_wp_loader_runs_the_load_order_fixtures_with_every_tag(): void
    {
        // Register 7.2 / section 8 wave 2: the fixtures export tagged legacy
        // copies (v0.2.5, v0.6.x) and hotfix/0.6.7, so the checkout needs
        // full history and tags.
        $job = self::workflow()['jobs']['wp-loader'] ?? [];

        $this->assertStringStartsWith('mysql:8.0', (string) ($job['services']['mysql']['image'] ?? ''));
        $this->assertStringContainsString('tests/harness/run.sh loader', self::run_lines($job));
        $checkout = array_values(array_filter($job['steps'] ?? [], static fn(array $s): bool => str_starts_with((string) ($s['uses'] ?? ''), 'actions/checkout@')));
        $this->assertSame(0, $checkout[0]['with']['fetch-depth'] ?? null);
    }

    public function test_wp_integration_runs_the_harness_on_a_mysql_8_0_service(): void
    {
        $job = self::workflow()['jobs']['wp-integration'] ?? [];

        $this->assertStringStartsWith('mysql:8.0', (string) ($job['services']['mysql']['image'] ?? ''));
        $this->assertStringContainsString('tests/harness/run.sh wp-integration', self::run_lines($job));
    }

    public function test_static_runs_phpstan_with_the_ci_config_and_validates_every_manifest(): void
    {
        $lines = self::run_lines(self::workflow()['jobs']['static'] ?? []);

        $this->assertStringContainsString('phpstan analyse -c phpstan.neon', $lines);
        $this->assertStringContainsString('composer validate --strict', $lines);
        $this->assertStringContainsString('packages/*/composer.json', $lines);
    }

    public function test_static_enforces_the_layer_rules_and_the_core_clean_install(): void
    {
        // Register section 8, wave-2 acceptance: deptrac 0 violations and the
        // ddd-core clean install, both on every push to extraction/**.
        $lines = self::run_lines(self::workflow()['jobs']['static'] ?? []);

        $this->assertStringContainsString('vendor/bin/deptrac analyse', $lines);
        $this->assertStringContainsString('phpstan analyse -c phpstan-core.neon', $lines);
        $this->assertStringContainsString('tests/Compat/core-clean-install.sh', $lines);
    }
}
