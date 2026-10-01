<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * CR-PK-5 (wave-2 packaging change requests, ratified in wave2-notes): three
 * transitional allowances were each to expire by the wave they name, and the
 * wave-4 gate fails on any that remain. tests/Compat/check-allowances.php is
 * that gate; `run.sh compat` runs it. This test runs it on the repository
 * (none may remain) and on a scratch tree that still has all three.
 */
final class TransitionalAllowancesTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array{int, string} */
    private static function check(string $root): array
    {
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::root() . '/tests/Compat/check-allowances.php')
            . ' ' . escapeshellarg($root) . ' 2>&1',
            $out,
            $code
        );

        return [$code, implode("\n", $out)];
    }

    public function test_no_cr_pk_5_allowance_remains_in_the_repository(): void
    {
        [$code, $out] = self::check(self::root());

        $this->assertSame(0, $code, $out);
        foreach (['deptrac skip_violations', 'phpstan-core scanDirectories', 'core-clean-install PENDING/SKIP'] as $name) {
            $this->assertStringContainsString("ok   CR-PK-5 {$name}: expired", $out);
        }
    }

    public function test_the_gate_names_every_allowance_that_remains(): void
    {
        $dir = sys_get_temp_dir() . '/ddd-allowances-' . bin2hex(random_bytes(4));
        mkdir($dir . '/tests/Compat', 0777, true);
        file_put_contents($dir . '/deptrac.yaml', <<<'YAML'
            deptrac:
              paths: [./packages/ddd-core/src]
              skip_violations:
                TangibleDDD\Runtime\SubscriptionRegistrar:
                  - TangibleDDD\Application\Process\ProcessRunner
            YAML);
        file_put_contents($dir . '/phpstan-core.neon', <<<'NEON'
            parameters:
              level: 0
              paths:
                - packages/ddd-core/src
              scanDirectories:
                - packages/ddd-wp/src
            NEON);
        file_put_contents($dir . '/tests/Compat/core-clean-install.sh', "#!/usr/bin/env bash\nif [ \"\${DDD_GATE:-0}\" = 1 ]; then exit 1; fi\necho SKIP example\n");
        file_put_contents($dir . '/tests/Compat/core-clean-install.php', "<?php\n\$gate = getenv('DDD_GATE') === '1';\nprintf(\"PENDING %d\\n\", 1);\n");

        try {
            [$code, $out] = self::check($dir);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('FAIL CR-PK-5 deptrac skip_violations: 1 depender(s) still skipped (TangibleDDD\\Runtime\\SubscriptionRegistrar)', $out);
        $this->assertStringContainsString('FAIL CR-PK-5 phpstan-core scanDirectories: core analysis still scans packages/ddd-wp/src', $out);
        $this->assertStringContainsString('FAIL CR-PK-5 core-clean-install PENDING/SKIP', $out);
    }

    public function test_the_gate_fails_when_a_judged_file_is_missing(): void
    {
        $dir = sys_get_temp_dir() . '/ddd-allowances-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            [$code, $out] = self::check($dir);
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString('deptrac.yaml is missing', $out);
    }
}
