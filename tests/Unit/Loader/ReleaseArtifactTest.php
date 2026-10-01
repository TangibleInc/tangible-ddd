<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a consumer vendoring tangible/ddd through a dist archive receives
 * (register 1.1 and 1.5, report F-15). The root artifact is the root files,
 * loader/, packages/ddd-core and packages/ddd-wp from the same commit.
 * Tests, docs, tools, examples, ddd-symfony and ddd-conformance never ship.
 *
 * Asserted through `git check-attr`, which evaluates .gitattributes from the
 * working tree, so the rule holds before anything is committed under these
 * paths. `git archive` omits a directory whose own path is export-ignored.
 */
class ReleaseArtifactTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function excluded(): array
    {
        $paths = [
            'tests', 'tools', 'docs', 'examples',
            'packages/ddd-symfony', 'packages/ddd-conformance',
            'packages/ddd-core/tests', 'packages/ddd-wp/tests',
            '.github', 'phpunit.xml', 'phpunit.integration.xml', 'phpstan.neon',
            'phpstan-deadcode.neon', 'phpstan-baseline.neon', 'deptrac.yaml',
            '.gitattributes', '.gitignore',
        ];

        return array_combine($paths, array_map(static fn(string $p): array => [$p], $paths));
    }

    /** @return array<string, array{string}> */
    public static function shipped(): array
    {
        $paths = [
            'tangible-ddd.php', 'composer.json', 'ddd-wordpress/self/index.php',
            'packages/ddd-core', 'packages/ddd-core/src', 'packages/ddd-core/composer.json',
            'packages/ddd-wp', 'packages/ddd-wp/src', 'packages/ddd-wp/wordpress',
            'packages/ddd-wp/composer.json',
            'loader', 'loader/tangible-ddd-0_7_0.php', 'loader/winner-autoloader.php',
            'loader/load-diagnostics.php', 'compat', 'compat/aliases.php',
        ];

        return array_combine($paths, array_map(static fn(string $p): array => [$p], $paths));
    }

    #[DataProvider('excluded')]
    public function test_dev_only_paths_are_export_ignored(string $path): void
    {
        $this->assertSame('set', $this->export_ignore($path), "{$path} must be export-ignore.");
    }

    #[DataProvider('shipped')]
    public function test_runtime_paths_ship_in_the_artifact(string $path): void
    {
        $this->assertSame('unspecified', $this->export_ignore($path), "{$path} must ship.");
    }

    private function export_ignore(string $path): string
    {
        $root = dirname(__DIR__, 3);
        if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
            $this->markTestSkipped('not a git checkout (e.g. a git-archive export)');
        }

        $out = shell_exec(
            'git -C ' . escapeshellarg($root) . ' check-attr export-ignore -- ' . escapeshellarg($path) . ' 2>/dev/null'
        );
        if (!is_string($out) || !preg_match('/: export-ignore: (\S+)\s*$/', $out, $m)) {
            $this->markTestSkipped('git check-attr unavailable');
        }

        return $m[1];
    }
}
