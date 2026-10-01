<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * tests/Compat/core-clean-install.sh needs Composer and network access, so
 * the unit suite only guards that it is present, executable and parses, and
 * that it asserts the frozen closure (register 1.5, section 8 wave 2).
 */
final class CompatScriptsTest extends TestCase
{
    public function test_the_clean_install_script_exists_parses_and_pins_the_closure(): void
    {
        $root = dirname(__DIR__, 3);
        $script = $root . '/tests/Compat/core-clean-install.sh';

        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), 'core-clean-install.sh is executable');
        exec('bash -n ' . escapeshellarg($script) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        $source = (string) file_get_contents($script);
        $this->assertStringContainsString('EXPECTED="league/tactician psr/container psr/log tangible/ddd-core"', $source);
        $this->assertStringContainsString('--no-dev', $source);
        $this->assertStringContainsString('"symlink": false', $source);

        exec(PHP_BINARY . ' -l ' . escapeshellarg($root . '/tests/Compat/core-clean-install.php') . ' 2>&1', $lint, $lint_code);
        $this->assertSame(0, $lint_code, implode("\n", $lint));
    }
}
