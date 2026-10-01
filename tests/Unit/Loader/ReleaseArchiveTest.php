<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * The release artifact check of register section 8 wave 4 (packaging):
 * `git archive HEAD | tar t` lists no tests, docs, tools, ddd-symfony or
 * ddd-conformance, and does list the matched ddd-core + ddd-wp pair, the
 * loader and the shim (register 1.1, 1.5).
 *
 * ReleaseArtifactTest judges the attributes path by path; this judges the
 * archive git really produces from the committed tree, through
 * tests/Compat/release-artifact.sh (the same script `run.sh compat` runs).
 */
final class ReleaseArchiveTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function script(): string
    {
        return self::root() . '/tests/Compat/release-artifact.sh';
    }

    /** @return array{int, string} */
    private static function check(string $repo, string $ref = 'HEAD'): array
    {
        $cmd = 'cd ' . escapeshellarg($repo) . ' && bash ' . escapeshellarg(self::script()) . ' ' . escapeshellarg($ref) . ' 2>&1';
        exec($cmd, $lines, $status);

        return [$status, implode("\n", $lines)];
    }

    private function require_git_checkout(): void
    {
        $root = self::root();
        if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
            $this->markTestSkipped('not a git checkout (e.g. a git-archive export)');
        }
    }

    public function test_the_script_exists_is_executable_and_parses(): void
    {
        $this->assertFileExists(self::script());
        $this->assertTrue(is_executable(self::script()), 'release-artifact.sh is executable');
        exec('bash -n ' . escapeshellarg(self::script()) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_the_archive_of_head_ships_only_the_runtime(): void
    {
        $this->require_git_checkout();

        [$code, $out] = self::check(self::root());

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('ok   no tests/, docs/, tools/, examples/, packages/ddd-symfony/, packages/ddd-conformance/ or packages/*/tests/ in the archive', $out);
        $this->assertStringContainsString('ok   loader/tangible-ddd-0_7_0.php', $out);
    }

    public function test_the_check_fails_on_an_archive_that_carries_dev_only_paths(): void
    {
        $repo = $this->scratch_repo(withAttributes: false);

        [$code, $out] = self::check($repo);

        $this->assertSame(1, $code, $out);
        foreach ([
            'tests/Unit/FooTest.php', 'docs/x.md', 'tools/t.php', 'examples/plain-php/run.php',
            'packages/ddd-symfony/composer.json', 'packages/ddd-conformance/composer.json',
            'packages/ddd-core/tests/Unit/BarTest.php',
        ] as $leak) {
            $this->assertStringContainsString("FAIL shipped: {$leak}", $out);
        }
    }

    public function test_the_check_fails_when_a_runtime_path_is_missing(): void
    {
        $repo = $this->scratch_repo(withAttributes: true, omit: 'packages/ddd-wp/wordpress/hooks.php');

        [$code, $out] = self::check($repo);

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('FAIL missing: packages/ddd-wp/wordpress/', $out);
    }

    public function test_the_check_passes_the_real_attributes_on_a_scratch_tree(): void
    {
        $repo = $this->scratch_repo(withAttributes: true);

        [$code, $out] = self::check($repo);

        $this->assertSame(0, $code, $out);
    }

    private function scratch_repo(bool $withAttributes, ?string $omit = null): string
    {
        exec('command -v git', $o, $has_git);
        if ($has_git !== 0) {
            $this->markTestSkipped('git unavailable');
        }
        $dir = sys_get_temp_dir() . '/ddd-archive-' . bin2hex(random_bytes(4));
        $files = [
            'tangible-ddd.php' => "<?php\n/**\n * Version: 0.7.0\n */\n",
            'composer.json' => '{}',
            'loader/tangible-ddd-0_7_0.php' => "<?php\n",
            'loader/winner-autoloader.php' => "<?php\n",
            'loader/load-diagnostics.php' => "<?php\n",
            'compat/aliases.php' => "<?php return [];\n",
            'ddd-wordpress/self/index.php' => "<?php\n",
            'packages/ddd-core/composer.json' => '{}',
            'packages/ddd-core/src/Runtime/HostDefaults.php' => "<?php\n",
            'packages/ddd-core/schema/mysql8/001.sql' => "SELECT 1;\n",
            'packages/ddd-wp/composer.json' => '{}',
            'packages/ddd-wp/src/Infra/Config.php' => "<?php\n",
            'packages/ddd-wp/wordpress/hooks.php' => "<?php\n",
            // dev-only
            'tests/Unit/FooTest.php' => "<?php\n",
            'docs/x.md' => "x\n",
            'tools/t.php' => "<?php\n",
            'examples/plain-php/run.php' => "<?php\n",
            'packages/ddd-symfony/composer.json' => '{}',
            'packages/ddd-conformance/composer.json' => '{}',
            'packages/ddd-core/tests/Unit/BarTest.php' => "<?php\n",
        ];
        if ($withAttributes) {
            $files['.gitattributes'] = (string) file_get_contents(self::root() . '/.gitattributes');
        }
        unset($files[(string) $omit]);
        foreach ($files as $path => $content) {
            @mkdir(dirname($dir . '/' . $path), 0777, true);
            file_put_contents($dir . '/' . $path, $content);
        }
        $q = escapeshellarg($dir);
        exec("git -C {$q} init -q && git -C {$q} add -A && git -C {$q} -c user.name=t -c user.email=t@t -c commit.gpgsign=false commit -q -m fixture 2>&1", $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->scratch[] = $dir;

        return $dir;
    }

    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }
}
