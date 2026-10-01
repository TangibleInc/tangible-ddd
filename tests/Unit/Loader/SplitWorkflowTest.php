<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The split-mirror workflow of register 1.5 / section 8 wave 4 (packaging):
 * splitsh-lite --prefix=packages/<name> for ddd-core, ddd-symfony and ddd-wp
 * (the last only as a layering artifact), one version line. It is
 * `workflow_dispatch` only and uses no secrets: it never runs on a push,
 * pushes nothing, and only hands the split commits back as artifacts until
 * the operator approves publishing.
 */
final class SplitWorkflowTest extends TestCase
{
    private static function path(): string
    {
        return dirname(__DIR__, 3) . '/.github/workflows/split.yml';
    }

    /** @return array<string, mixed> */
    private static function workflow(): array
    {
        self::assertFileExists(self::path());

        return Yaml::parseFile(self::path());
    }

    /** @return list<array<string, mixed>> */
    private static function steps(): array
    {
        return self::workflow()['jobs']['split']['steps'] ?? [];
    }

    private static function run_lines(): string
    {
        return implode("\n", array_map(static fn(array $s): string => (string) ($s['run'] ?? ''), self::steps()));
    }

    public function test_it_is_triggered_by_workflow_dispatch_only(): void
    {
        $wf = self::workflow();
        $on = $wf['on'] ?? $wf[true] ?? null; // YAML 1.1 reads a bare `on` as true

        $this->assertIsArray($on);
        $this->assertSame(['workflow_dispatch'], array_keys($on), 'no push, pull_request, schedule, workflow_run or release trigger');
    }

    public function test_it_uses_no_secrets_and_cannot_write(): void
    {
        $source = (string) file_get_contents(self::path());

        $this->assertDoesNotMatchRegularExpression('/secrets\s*\./i', $source, 'no secrets of any kind');
        $this->assertStringNotContainsString('GITHUB_TOKEN', $source);
        $this->assertStringNotContainsString('github.token', $source);
        $this->assertSame(['contents' => 'read'], self::workflow()['permissions'] ?? null);
        $this->assertDoesNotMatchRegularExpression('/\bgit\s+push\b/', self::run_lines(), 'the workflow publishes nothing');
        foreach (self::steps() as $step) {
            if (str_starts_with((string) ($step['uses'] ?? ''), 'actions/checkout@')) {
                $this->assertFalse($step['with']['persist-credentials'] ?? true, 'checkout keeps no credentials');
            }
        }
    }

    public function test_it_splits_exactly_the_three_package_prefixes_with_splitsh_lite(): void
    {
        $matrix = self::workflow()['jobs']['split']['strategy']['matrix']['include'] ?? [];
        $by_prefix = array_column($matrix, 'package', 'prefix');

        $this->assertSame([
            'packages/ddd-core' => 'tangible/ddd-core',
            'packages/ddd-symfony' => 'tangible/ddd-symfony',
            'packages/ddd-wp' => 'tangible/ddd-wp',
        ], $by_prefix);
        $this->assertStringContainsString('splitsh-lite --prefix="${{ matrix.prefix }}"', self::run_lines());
    }

    public function test_each_prefix_holds_the_package_it_names(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (self::workflow()['jobs']['split']['strategy']['matrix']['include'] as $entry) {
            $manifest = json_decode((string) file_get_contents($root . '/' . $entry['prefix'] . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($entry['package'], $manifest['name'], $entry['prefix']);
        }
        $this->assertStringContainsString('jq -r .name', self::run_lines(), 'the split tree is checked to carry the right manifest');
    }

    public function test_the_tools_are_pinned_and_the_split_sees_full_history(): void
    {
        $source = (string) file_get_contents(self::path());

        $this->assertMatchesRegularExpression('/SPLITSH_LITE_REF:\s*[0-9a-f]{40}\b/', $source, 'splitsh-lite pinned by commit');
        $this->assertMatchesRegularExpression('/LIBGIT2_VERSION:\s*1\.5\.\d+/', $source, 'git2go v34 needs libgit2 1.5');
        $this->assertMatchesRegularExpression('/LIBGIT2_SHA256:\s*[0-9a-f]{64}\b/', $source, 'the libgit2 tarball is checksummed');

        $checkout = array_values(array_filter(self::steps(), static fn(array $s): bool => str_starts_with((string) ($s['uses'] ?? ''), 'actions/checkout@')));
        $this->assertSame(0, $checkout[0]['with']['fetch-depth'] ?? null);
    }

    public function test_the_split_commits_are_handed_back_not_published(): void
    {
        $uses = array_map(static fn(array $s): string => (string) ($s['uses'] ?? ''), self::steps());

        $this->assertNotEmpty(array_filter($uses, static fn(string $u): bool => str_starts_with($u, 'actions/upload-artifact@')));
        $this->assertStringContainsString('git bundle create', self::run_lines());
        $this->assertArrayHasKey('ref', self::workflow()['on']['workflow_dispatch']['inputs'] ?? self::workflow()[true]['workflow_dispatch']['inputs'] ?? []);
    }
}
