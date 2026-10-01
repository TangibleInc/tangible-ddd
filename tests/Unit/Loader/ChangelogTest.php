<?php

declare(strict_types=1);

namespace TangibleDDD\Tests\Unit\Loader;

use PHPUnit\Framework\TestCase;

/**
 * CHANGELOG.md carries the release line of the plugin header (register
 * section 8 wave 4, packaging): the package split, the three fixed 0.6.x
 * bugs (section 6) and migration notes for WordPress consumers.
 */
final class ChangelogTest extends TestCase
{
    private static function changelog(): string
    {
        $path = dirname(__DIR__, 3) . '/CHANGELOG.md';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_the_top_entry_is_the_plugin_header_version_and_unreleased(): void
    {
        $header = (string) file_get_contents(dirname(__DIR__, 3) . '/tangible-ddd.php');
        preg_match('/^\s*\*\s*Version:\s*(\S+)/m', $header, $m);

        preg_match('/^## (\S+)(.*)$/m', self::changelog(), $top);
        $this->assertSame($m[1], $top[1], 'the newest entry is the version the loader registers');
        $this->assertStringContainsString('unreleased', $top[2], 'nothing is tagged or published in this project');
    }

    public function test_it_describes_the_split_the_three_fixes_and_the_wordpress_migration(): void
    {
        $text = self::changelog();

        foreach (['tangible/ddd-core', 'tangible/ddd-wp', 'tangible/ddd-symfony', 'tangible/ddd-conformance'] as $package) {
            $this->assertStringContainsString("`{$package}`", $text);
        }
        foreach (['GET_LOCK', '#[StartsOn]', 'delayed twice', 'hotfix/0.6.7'] as $fix) {
            $this->assertStringContainsString($fix, $text);
        }
        $this->assertStringContainsString('### Migrating a WordPress plugin from 0.6.x', $text);
        $this->assertStringContainsString('Keep requiring `tangible/ddd`', $text);
        $this->assertStringContainsString('wp ddd drain --before-rollback', $text);
        $this->assertStringContainsString('MariaDB is not claimed', $text);
    }
}
